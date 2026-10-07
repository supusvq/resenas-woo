<?php
/**
 * Cliente de licencias de SupuHub (contrato: docs/CLIENT-INTEGRATION-CONTRACT.md
 * del repositorio de SupuHub). Ver también SUPUHUB.md de este plugin.
 *
 * Política: sin licencia válida el plugin sigue mostrando las reseñas guardadas
 * y enviando los emails de petición de reseña. Solo la importación desde Google
 * (botón manual y tarea semanal) exige licencia válida (trial o activa).
 *
 * SupuHub es la fuente de verdad: aquí solo se guarda el último estado recibido.
 * Una caída de red nunca se interpreta como licencia revocada: se conserva el
 * último estado válido durante la gracia y se reintenta.
 */

namespace MRG\License;

if (!defined('ABSPATH')) {
    exit;
}

class License
{
    // Código registrado en SupuHub (products.code).
    const PRODUCT_CODE = 'resenaswoo';
    const LICENSE_MODE = 'domain';

    // Enlace de pago de Stripe (LIVE) creado por supuhub-release: 49 €/año, 15 días de prueba.
    const BUY_URL = 'https://buy.stripe.com/9B67sLclbcSlddL3GRcIE04';

    // Producción. Una instalación de pruebas apunta a staging con la constante
    // SUPUHUB_API_BASE en wp-config.php.
    const SUPUHUB_API_BASE = 'https://api.supudigital.es/api/';

    const HOOK = 'mrg_license_check';
    const OPTION = 'mrg_license';
    const INSTALL_OPTION = 'mrg_installation_id';
    const KEY_FORMAT = '/^[A-Z]{4,12}(-[A-Z0-9]{4}){3,5}$/';

    const TIMEOUT_SECONDS = 15;
    const UPDATE_TIMEOUT_SECONDS = 5; // Corto: WordPress comprueba actualizaciones al cargar el escritorio.
    const CHECK_HOURS = 12;           // Revalidación normal.
    const RETRY_HOURS = 1;            // Reintento tras un fallo de red.
    const GRACE_HOURS = 72;           // Margen extra si SupuHub no responde.

    /**
     * Registra la revalidación periódica. Se llama en todas las peticiones
     * (también las de WP-Cron), no solo en el admin.
     */
    public static function hooks()
    {
        add_action(self::HOOK, [__CLASS__, 'maybe_check']);

        if (self::has_key() && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Estado local. Nunca contiene la clave en claro.
     */
    public static function state()
    {
        $stored = get_option(self::OPTION, []);

        return array_replace(
            [
                'key_enc' => '',
                'key_iv' => '',
                'key_tag' => '',
                'key_masked' => '',
                'status' => '',
                'active' => false, // "active" de la última respuesta de SupuHub.
                'product' => '',
                'expires_at' => '',
                'updates' => null,
                'domain' => '',
                'environment' => '',
                'sites_used' => null,
                'sites_limit' => null,
                'reason' => '',
                'last_check_at' => '',
                'last_valid_at' => '',
                'next_check_at' => '',
                'last_error' => '',
            ],
            is_array($stored) ? $stored : []
        );
    }

    public static function has_key()
    {
        return '' !== self::state()['key_enc'];
    }

    public static function supuhub_url($path)
    {
        $base = defined('SUPUHUB_API_BASE') ? (string) SUPUHUB_API_BASE : self::SUPUHUB_API_BASE;

        return rtrim($base, '/') . '/' . ltrim((string) $path, '/');
    }

    /**
     * UUID persistente de la instalación. No se borra al desinstalar: reinstalar
     * no debe consumir otra plaza ni reiniciar la prueba.
     */
    public static function installation_id()
    {
        $id = (string) get_option(self::INSTALL_OPTION, '');
        if ('' === $id) {
            $id = wp_generate_uuid4();
            add_option(self::INSTALL_OPTION, $id, '', 'yes');
        }

        return $id;
    }

    /**
     * True si la licencia permite importar desde Google ahora mismo.
     *
     * Requiere trial/active confirmado por SupuHub, de ESTE producto, y que la
     * última respuesta válida esté dentro de la revalidación más la gracia.
     * Un expired/suspended/revoked confirmado se aplica de inmediato: la gracia
     * es para caídas de red, no para caducidades.
     */
    public static function is_valid()
    {
        $state = self::state();

        if ('' === $state['key_enc']) {
            return false;
        }
        if (!in_array($state['status'], ['active', 'trial'], true)) {
            return false;
        }
        // La última respuesta recibida tiene que haber dicho active === true: una
        // negativa explícita quita el permiso al momento, aunque el status diga otra cosa.
        if (true !== $state['active']) {
            return false;
        }
        // SupuHub valida cualquier clave suya: una de otro producto no vale aquí.
        if (self::PRODUCT_CODE !== $state['product']) {
            return false;
        }

        $valid_at = strtotime((string) $state['last_valid_at'] . ' UTC');
        if (!$valid_at) {
            return false;
        }

        return (time() - $valid_at) < (self::CHECK_HOURS + self::GRACE_HOURS) * HOUR_IN_SECONDS;
    }

    /**
     * Alias con nombre de negocio: lo único que bloquea la licencia es importar.
     */
    public static function can_import()
    {
        return (bool) apply_filters('mrg_license_can_import', self::is_valid());
    }

    /**
     * Mensaje para cuando la importación está bloqueada (texto plano, sin HTML).
     */
    public static function import_blocked_message()
    {
        return sprintf(
            /* translators: %s: URL de compra */
            __('Para importar reseñas de Google necesitas una licencia válida. Actívala en Reseñas Woo > Licencia o consíguela en %s. Las reseñas guardadas se siguen mostrando y los emails se siguen enviando.', 'mis-resenas-de-google'),
            self::BUY_URL
        );
    }

    public static function is_unreachable()
    {
        return self::has_key() && '' !== self::state()['last_error'];
    }

    /**
     * Código de estado para la interfaz: none, other_product, trial, active,
     * expired, suspended, revoked, invalid.
     */
    public static function status_code()
    {
        $state = self::state();

        if (!self::has_key()) {
            return 'none';
        }
        if (in_array($state['status'], ['active', 'trial'], true) && self::PRODUCT_CODE !== $state['product']) {
            return 'other_product';
        }
        if (in_array($state['status'], ['active', 'trial'], true) && !self::is_valid()) {
            // Trial o activa, pero sin confirmar desde hace más de la gracia.
            return 'invalid';
        }
        if (in_array($state['status'], ['trial', 'active', 'expired', 'suspended', 'revoked'], true)) {
            return $state['status'];
        }

        return 'invalid';
    }

    public static function status_label()
    {
        switch (self::status_code()) {
            case 'none':
                return __('Sin licencia', 'mis-resenas-de-google');
            case 'other_product':
                return __('La clave no es de Reseñas Woo', 'mis-resenas-de-google');
            case 'trial':
                return __('Prueba activa', 'mis-resenas-de-google');
            case 'active':
                return __('Licencia activa', 'mis-resenas-de-google');
            case 'expired':
                return __('Licencia caducada', 'mis-resenas-de-google');
            case 'suspended':
                return __('Licencia suspendida', 'mis-resenas-de-google');
            case 'revoked':
                return __('Licencia revocada', 'mis-resenas-de-google');
            default:
                return __('Licencia no válida', 'mis-resenas-de-google');
        }
    }

    /**
     * Activa una clave en esta instalación y guarda el estado devuelto.
     *
     * @throws \RuntimeException Con un mensaje apto para el administrador.
     */
    public static function activate($key)
    {
        $key = strtoupper(trim((string) $key));

        if (!preg_match(self::KEY_FORMAT, $key)) {
            throw new \RuntimeException(__('El formato de la clave no es válido. Cópiala entera (por ejemplo RESENAS-XXXX-XXXX-XXXX).', 'mis-resenas-de-google'));
        }

        // Comprueba que se puede cifrar ANTES de consumir una plaza en SupuHub.
        if (!Crypto::can_encrypt()) {
            throw new \RuntimeException(__('No se puede guardar la licencia de forma segura: faltan las claves de seguridad de wp-config.php o la extensión OpenSSL de PHP.', 'mis-resenas-de-google'));
        }

        $data = self::request(
            'license/activate.php',
            [
                'installation_id' => self::installation_id(),
                'site_url' => home_url('/'),
                'plugin_version' => MRG_VERSION,
                'wp_version' => get_bloginfo('version'),
            ],
            $key
        );

        if (empty($data['active'])) {
            throw new \RuntimeException(self::denial_message($data));
        }

        if (self::PRODUCT_CODE !== (isset($data['product']) ? $data['product'] : '')) {
            // SupuHub ya ha ocupado una plaza de ese otro producto: se libera.
            try {
                self::request('license/deactivate.php', ['installation_id' => self::installation_id()], $key);
            } catch (\Throwable $e) {
                unset($e);
            }
            throw new \RuntimeException(__('Esa clave es de otro producto de SupuDigital, no de Reseñas Woo. Revisa que has copiado la licencia correcta.', 'mis-resenas-de-google'));
        }

        $encrypted = Crypto::encrypt($key);

        $state = self::state();
        $state['key_enc'] = $encrypted['enc'];
        $state['key_iv'] = $encrypted['iv'];
        $state['key_tag'] = $encrypted['tag'];
        $state['key_masked'] = self::mask($key);
        // Una activación no trae el campo "updates": se olvida el de otra clave.
        $state['updates'] = null;

        self::save(self::apply_response($data, $state));

        // Con clave nueva, la respuesta de actualización guardada ya no vale.
        Updater::forget_offer();
        delete_site_transient('update_plugins');

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    /**
     * Revalidación contra SupuHub. Propaga el error para la comprobación manual.
     *
     * @throws \RuntimeException Si no hay clave o SupuHub no responde bien.
     */
    public static function check()
    {
        $key = self::key();
        if (null === $key) {
            throw new \RuntimeException(self::missing_key_message());
        }

        $state = self::state();

        try {
            $data = self::request('license/status.php', ['installation_id' => self::installation_id()], $key);
        } catch (\Throwable $e) {
            // Fallo de red: se conserva el estado anterior y se reintenta en 1 h.
            $state['last_error'] = $e->getMessage();
            $state['next_check_at'] = gmdate('Y-m-d H:i:s', time() + self::RETRY_HOURS * HOUR_IN_SECONDS);
            self::save($state);
            throw $e;
        }

        self::save(self::apply_response($data, $state));

        // Una negativa invalida también el paquete de actualización guardado, el nuestro y el de WordPress.
        if (!self::is_valid()) {
            Updater::forget_offer();
        }
    }

    /**
     * Revalidación periódica desde WP-Cron (cada hora mira si toca). Nunca lanza.
     */
    public static function maybe_check()
    {
        if (!self::has_key()) {
            return;
        }

        $next = strtotime((string) self::state()['next_check_at'] . ' UTC');
        if ($next && $next > time()) {
            return;
        }

        try {
            self::check();
        } catch (\Throwable $e) {
            // Queda en last_error; el cron no debe romperse.
            unset($e);
        }
    }

    /**
     * Pregunta a SupuHub si hay versión nueva. La interpretación la hace Updater.
     *
     * @throws \RuntimeException Si no hay clave o SupuHub no responde bien.
     */
    public static function update_check()
    {
        $key = self::key();
        if (null === $key) {
            throw new \RuntimeException(self::missing_key_message());
        }

        return self::request(
            'update/check.php',
            [
                'installation_id' => self::installation_id(),
                'site_url' => home_url('/'),
                'version' => MRG_VERSION,
                'wp_version' => get_bloginfo('version'),
            ],
            $key,
            self::UPDATE_TIMEOUT_SECONDS
        );
    }

    /**
     * Libera la plaza en SupuHub y borra la licencia local.
     *
     * @throws \RuntimeException Si no se pudo avisar a SupuHub.
     */
    public static function deactivate()
    {
        $key = self::key();
        if (null === $key) {
            // Clave ilegible (salts cambiadas): no se puede avisar a SupuHub,
            // pero sí dejar el sitio limpio para activar otra vez.
            self::forget();
            return;
        }

        self::request('license/deactivate.php', ['installation_id' => self::installation_id()], $key);

        self::forget();
    }

    /**
     * Borra la licencia local sin avisar a SupuHub (el installation_id se conserva).
     */
    public static function forget()
    {
        delete_option(self::OPTION);
        Updater::forget_offer();
        delete_site_transient('update_plugins');
        self::unschedule();
    }

    /* ------------------------------------------------------------------ */
    /* Internos                                                           */
    /* ------------------------------------------------------------------ */

    private static function key()
    {
        $state = self::state();

        return Crypto::decrypt($state['key_enc'], $state['key_iv'], $state['key_tag']);
    }

    private static function missing_key_message()
    {
        return self::has_key()
            ? __('No se puede leer la licencia guardada (¿han cambiado las claves de seguridad de wp-config.php?). Desactívala y vuelve a introducirla.', 'mis-resenas-de-google')
            : __('No hay ninguna licencia guardada.', 'mis-resenas-de-google');
    }

    private static function save(array $state)
    {
        // Autoload: hooks() la lee en cada petición.
        update_option(self::OPTION, $state, true);
    }

    /**
     * Vuelca la respuesta de SupuHub sobre el estado local. Los campos
     * desconocidos se ignoran a propósito: SupuHub puede ampliarlos.
     */
    private static function apply_response(array $data, array $state)
    {
        $active = !empty($data['active']);
        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : '';
        if ('' === $status) {
            $status = $active ? 'active' : 'invalid';
        }

        $now = gmdate('Y-m-d H:i:s');

        $state['status'] = $status;
        $state['active'] = $active;
        $state['product'] = isset($data['product']) ? (string) $data['product'] : $state['product'];
        $state['expires_at'] = isset($data['expires_at']) ? (string) $data['expires_at'] : '';
        $state['reason'] = isset($data['reason']) ? (string) $data['reason'] : '';
        $state['last_check_at'] = $now;
        $state['last_error'] = '';
        $state['next_check_at'] = gmdate('Y-m-d H:i:s', time() + self::CHECK_HOURS * HOUR_IN_SECONDS);

        if (array_key_exists('updates', $data)) {
            $state['updates'] = (bool) $data['updates'];
        }
        foreach (['domain', 'environment'] as $field) {
            if (isset($data[$field])) {
                $state[$field] = (string) $data[$field];
            }
        }
        foreach (['sites_used', 'sites_limit'] as $field) {
            if (array_key_exists($field, $data)) {
                $state[$field] = null === $data[$field] ? null : (int) $data[$field];
            }
        }

        // Solo cuenta como válida si es de ESTE producto. Cualquier otra respuesta
        // de SupuHub (negativa, otro producto, estado raro) borra la última validez:
        // la gracia de 72 h es solo para fallos de red, nunca para una negativa.
        if ($active && in_array($status, ['active', 'trial'], true) && self::PRODUCT_CODE === $state['product']) {
            $state['last_valid_at'] = $now;
        } else {
            $state['last_valid_at'] = '';
        }

        return $state;
    }

    /**
     * POST JSON a SupuHub. La clave viaja en cabecera, nunca en la URL.
     *
     * @throws \RuntimeException Error de red, HTTP >= 400 o respuesta no JSON.
     */
    private static function request($path, array $body, $key, $timeout = self::TIMEOUT_SECONDS)
    {
        $response = wp_remote_post(
            self::supuhub_url($path),
            [
                'timeout' => $timeout,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-License-Key' => $key,
                    'User-Agent' => 'ResenasWoo-WP/' . MRG_VERSION,
                ],
                'body' => wp_json_encode($body),
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('No se pudo contactar con SupuHub: ', 'mis-resenas-de-google') . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($data)) {
            /* translators: %d: código HTTP */
            throw new \RuntimeException(sprintf(__('SupuHub devolvió una respuesta no válida (HTTP %d).', 'mis-resenas-de-google'), $status));
        }

        if ($status >= 400) {
            throw new \RuntimeException(
                !empty($data['error'])
                    ? (string) $data['error']
                    /* translators: %d: código HTTP */
                    : sprintf(__('SupuHub no está disponible ahora mismo (HTTP %d).', 'mis-resenas-de-google'), $status)
            );
        }

        return $data;
    }

    private static function denial_message(array $data)
    {
        foreach (['reason', 'error'] as $field) {
            if (!empty($data[$field])) {
                return (string) $data[$field];
            }
        }

        return __('SupuHub no aceptó esta licencia.', 'mis-resenas-de-google');
    }

    /**
     * RESENAS-A1B2-C3D4-E5F6 -> RESENAS-••••-••••-E5F6.
     */
    public static function mask($key)
    {
        $parts = explode('-', (string) $key);
        if (count($parts) < 3) {
            return str_repeat('•', 8);
        }

        $first = array_shift($parts);
        $last = array_pop($parts);

        return $first . str_repeat('-••••', count($parts)) . '-' . $last;
    }
}
