<?php
/**
 * Actualizaciones desde SupuHub (docs/ENCARGO-PLUGIN-ACTUALIZACIONES.md del
 * repositorio de SupuHub).
 *
 * El plugin no decide nada: pregunta a SupuHub con la licencia guardada y le
 * pasa a WordPress lo que conteste. SupuHub solo entrega el ZIP (package) con
 * licencia válida. Aquí se guarda la última respuesta para cuando no responda.
 *
 * Requiere la cabecera "Update URI: https://api.supudigital.es/products/resenaswoo"
 * en el archivo principal: sin ella WordPress no dispara update_plugins_{host}.
 */

namespace MRG\License;

if (!defined('ABSPATH')) {
    exit;
}

class Updater
{
    // Host del Update URI: WordPress nombra el filtro con él.
    const HOST = 'api.supudigital.es';
    const CACHE_OPTION = 'mrg_update_cache';
    const NAME = 'Reseñas Woo';
    const CACHE_TTL_HOURS = 12; // La respuesta guardada caduca: no se reutiliza para siempre.

    /**
     * Registra los filtros. También fuera del admin: WP-Cron comprueba
     * actualizaciones en segundo plano.
     */
    public static function hooks()
    {
        add_filter('update_plugins_' . self::HOST, [__CLASS__, 'check_update'], 10, 4);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 10, 3);
        add_action('in_plugin_update_message-' . self::plugin_file(), [__CLASS__, 'license_notice'], 10, 2);
    }

    /**
     * Filtro update_plugins_{host}.
     */
    public static function check_update($update, $plugin_data, $plugin_file, $locales)
    {
        if (self::plugin_file() !== $plugin_file) {
            return $update;
        }

        if (!License::has_key()) {
            // La respuesta guardada era de una licencia que ya no está.
            delete_option(self::CACHE_OPTION);
            return $update;
        }

        try {
            $data = License::update_check();
            if (self::is_for_this_plugin($data)) {
                // Se guarda cualquier respuesta, también las negativas (sin package):
                // así una denegación sustituye al paquete guardado antes.
                update_option(self::CACHE_OPTION, ['time' => time(), 'data' => $data], false);
            }
        } catch (\Throwable $e) {
            // Caída o timeout: vale lo último que se supo, sin package si la
            // licencia no es válida ahora y solo durante CACHE_TTL_HOURS.
            $data = self::cached();
        }

        return is_array($data) && self::is_for_this_plugin($data) ? self::to_wordpress($data) : $update;
    }

    /**
     * Filtro plugins_api: la ficha "Ver detalles" de este plugin.
     */
    public static function plugin_info($result, $action, $args)
    {
        if ('plugin_information' !== $action || !is_object($args) || (isset($args->slug) ? $args->slug : '') !== self::slug()) {
            return $result;
        }

        $data = License::has_key() ? self::cached() : null;
        $data = is_array($data) && self::is_for_this_plugin($data) ? $data : [];
        $changelog = trim((string) (isset($data['changelog']) ? $data['changelog'] : ''));

        return (object) [
            'name' => self::NAME,
            'slug' => self::slug(),
            'version' => self::version_of($data),
            'author' => '<a href="https://www.supudigital.es">Juan Gallardo by SupuDigital</a>',
            'homepage' => License::BUY_URL,
            'requires' => (string) (isset($data['requires']) ? $data['requires'] : ''),
            'tested' => (string) (isset($data['tested']) ? $data['tested'] : ''),
            'requires_php' => (string) (isset($data['requires_php']) ? $data['requires_php'] : ''),
            'download_link' => !empty($data['update']) ? (string) (isset($data['package']) ? $data['package'] : '') : '',
            'sections' => [
                'changelog' => '' !== $changelog
                    ? nl2br(esc_html($changelog))
                    : esc_html__('Sin notas para esta versión.', 'mis-resenas-de-google'),
            ],
        ];
    }

    /**
     * Respuesta guardada, o null si no hay o ha caducado. Nunca devuelve un
     * package si la licencia local no es válida en este momento.
     */
    public static function cached()
    {
        $cache = get_option(self::CACHE_OPTION);
        if (!is_array($cache) || !isset($cache['time'], $cache['data']) || !is_array($cache['data'])) {
            return null;
        }
        if (time() - (int) $cache['time'] > self::CACHE_TTL_HOURS * HOUR_IN_SECONDS) {
            delete_option(self::CACHE_OPTION);
            return null;
        }

        $data = $cache['data'];
        if (!License::is_valid()) {
            $data['package'] = '';
        }

        return $data;
    }

    /**
     * Bajo el aviso de versión nueva en Plugins, el porqué de que no se pueda instalar.
     */
    public static function license_notice($plugin_data, $response)
    {
        if (!is_object($response) || !empty($response->package) || empty($response->upgrade_notice)) {
            return;
        }

        printf(
            ' <strong>%s</strong> <a href="%s">%s</a>',
            esc_html((string) $response->upgrade_notice),
            esc_url(admin_url('admin.php?page=mrg-license')),
            esc_html__('Revisar la licencia', 'mis-resenas-de-google')
        );
    }

    /**
     * Solo los campos que entiende WordPress.
     */
    public static function to_wordpress(array $data)
    {
        $version = self::version_of($data);
        $available = !empty($data['update']);

        $update = [
            'slug' => self::slug(),
            'version' => $version,
            'new_version' => $version,
            'url' => (string) (isset($data['url']) ? $data['url'] : ''),
            'package' => $available ? (string) (isset($data['package']) ? $data['package'] : '') : '',
            'tested' => (string) (isset($data['tested']) ? $data['tested'] : ''),
            'requires' => (string) (isset($data['requires']) ? $data['requires'] : ''),
            'requires_php' => (string) (isset($data['requires_php']) ? $data['requires_php'] : ''),
        ];

        // Versión nueva sin descarga: el cliente debe ver por qué.
        if ($available && '' === $update['package'] && !empty($data['reason'])) {
            $update['upgrade_notice'] = (string) $data['reason'];
        }

        return $update;
    }

    /**
     * SupuHub contesta con el producto de la clave activada, no con el de quien
     * pregunta: con la clave de otro producto ofrecería el ZIP de otro plugin.
     */
    public static function is_for_this_plugin(array $data)
    {
        return empty($data['update']) || (isset($data['plugin']) ? $data['plugin'] : '') === self::plugin_file();
    }

    private static function version_of(array $data)
    {
        if (isset($data['new_version'])) {
            return (string) $data['new_version'];
        }
        if (isset($data['version'])) {
            return (string) $data['version'];
        }
        return MRG_VERSION;
    }

    /**
     * resenas-woo/mis-resenas-de-google.php
     */
    private static function plugin_file()
    {
        return MRG_BASENAME;
    }

    /**
     * resenas-woo
     */
    private static function slug()
    {
        return dirname(self::plugin_file());
    }
}
