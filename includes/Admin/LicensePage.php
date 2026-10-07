<?php
/**
 * Pantalla Reseñas Woo > Licencia: clave de SupuHub, estado, versión antigua y
 * opción de borrar datos al desinstalar. Todas las acciones van por POST a
 * admin-post.php con nonce, capacidad manage_options y redirección (PRG).
 * La clave completa nunca se imprime.
 */

namespace MRG\Admin;

use MRG\License\License;
use MRG\Migration;

if (!defined('ABSPATH')) {
    exit;
}

class LicensePage
{
    const SLUG = 'mrg-license';
    const NOTICE_TRANSIENT = 'mrg_license_notice_';

    public function init()
    {
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_post_mrg_license', [$this, 'handle_license']);
        add_action('admin_post_mrg_remove_old', [$this, 'handle_remove_old']);
        add_action('admin_post_mrg_data_settings', [$this, 'handle_data_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function register_menu()
    {
        add_submenu_page(
            'mrg-settings',
            __('Licencia', 'mis-resenas-de-google'),
            __('Licencia', 'mis-resenas-de-google'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Acciones (POST)                                                    */
    /* ------------------------------------------------------------------ */

    public function handle_license()
    {
        $this->guard('mrg_license');

        $op = sanitize_key(wp_unslash((string) ($_POST['op'] ?? '')));

        try {
            switch ($op) {
                case 'activate':
                    License::activate((string) wp_unslash($_POST['license_key'] ?? ''));
                    $this->done('success', __('Licencia activada.', 'mis-resenas-de-google'));
                    break;
                case 'check':
                    License::check();
                    $this->done('success', __('Licencia comprobada.', 'mis-resenas-de-google'));
                    break;
                case 'deactivate':
                    License::deactivate();
                    $this->done('success', __('Licencia desactivada en esta web.', 'mis-resenas-de-google'));
                    break;
                default:
                    $this->done('error', __('Acción no válida.', 'mis-resenas-de-google'));
            }
        } catch (\Throwable $e) {
            $this->done('error', $e->getMessage());
        }
    }

    public function handle_remove_old()
    {
        $this->guard('mrg_remove_old');

        // Además de manage_options: poder borrar plugins (falso con DISALLOW_FILE_MODS)
        // y no estar en multisitio (ver Migration::removal_blocked_reason()).
        $blocked = Migration::removal_blocked_reason();
        if ('' !== $blocked) {
            $this->done('error', $blocked);
        }

        if (empty($_POST['confirm'])) {
            $this->done('error', __('Marca la casilla de confirmación para borrar la versión antigua.', 'mis-resenas-de-google'));
        }

        try {
            Migration::delete_old_folder();
        } catch (\Throwable $e) {
            $this->done('error', $e->getMessage());
        }
        $this->done('success', __('Versión antigua eliminada. Tus reseñas y ajustes siguen intactos.', 'mis-resenas-de-google'));
    }

    public function handle_data_settings()
    {
        $this->guard('mrg_data_settings');

        update_option(Migration::DELETE_DATA_OPTION, empty($_POST['delete_data']) ? 0 : 1, false);
        $this->done('success', __('Ajuste guardado.', 'mis-resenas-de-google'));
    }

    private function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'mis-resenas-de-google'), '', ['response' => 403]);
        }
        check_admin_referer($action);
    }

    /**
     * Guarda el aviso para el usuario actual y redirige a la pantalla (PRG).
     */
    private function done($type, $message)
    {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), ['type' => $type, 'message' => (string) $message], 120);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Avisos                                                             */
    /* ------------------------------------------------------------------ */

    private function is_plugin_screen()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        return 0 === strpos($page, 'mrg-');
    }

    public function admin_notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_plugins = $screen && 'plugins' === $screen->id;

        // No se pudo desarmar el desinstalador de la 2.x: aviso en todo el admin.
        $uninstall_error = get_option(Migration::UNINSTALL_ERROR_OPTION, '');
        if ('' !== (string) $uninstall_error && Migration::old_folder_present()) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Reseñas Woo:', 'mis-resenas-de-google') . '</strong> '
                . esc_html((string) $uninstall_error) . '</p></div>';
        }

        // Versión antigua presente: en Plugins y en las pantallas del plugin.
        if (Migration::old_folder_present() && ($on_plugins || $this->is_plugin_screen())) {
            echo '<div class="notice notice-error"><p><strong>'
                . esc_html__('No borres la versión antigua desde Plugins: su desinstalador borra las reseñas.', 'mis-resenas-de-google')
                . '</strong> ';
            printf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::SLUG . '#mrg-old-version')),
                esc_html__('Eliminar la versión antigua de forma segura', 'mis-resenas-de-google')
            );
            echo '</p></div>';
        }

        if (!$this->is_plugin_screen()) {
            return;
        }

        // Sin licencia válida: aviso en todas las pantallas del plugin menos la de Licencia.
        $page = sanitize_key(wp_unslash((string) $_GET['page']));
        if (self::SLUG !== $page && !License::is_valid()) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Sin licencia válida no se importan reseñas de Google. Las reseñas guardadas se siguen mostrando y los emails se siguen enviando.', 'mis-resenas-de-google')
                . ' ';
            printf(
                '<a href="%s">%s</a> · <a href="%s" target="_blank" rel="noopener">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::SLUG)),
                esc_html__('Activar licencia', 'mis-resenas-de-google'),
                esc_url(License::BUY_URL),
                esc_html__('Comprar', 'mis-resenas-de-google')
            );
            echo '</p></div>';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Pantalla                                                           */
    /* ------------------------------------------------------------------ */

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $state = License::state();
        $code = License::status_code();
        $notice = get_transient(self::NOTICE_TRANSIENT . get_current_user_id());
        if ($notice) {
            delete_transient(self::NOTICE_TRANSIENT . get_current_user_id());
        }

        echo '<div class="wrap"><h1>' . esc_html__('Licencia', 'mis-resenas-de-google') . '</h1>';

        if (is_array($notice) && !empty($notice['message'])) {
            $class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }

        echo '<p>' . esc_html__('La licencia permite importar reseñas de Google (botón y tarea semanal) y recibir actualizaciones. Sin ella, las reseñas guardadas se siguen mostrando y los emails se siguen enviando.', 'mis-resenas-de-google') . '</p>';

        // Estado.
        $color = License::is_valid() ? '#00a32a' : ('none' === $code ? '#646970' : '#d63638');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th>' . esc_html__('Estado', 'mis-resenas-de-google') . '</th><td><strong style="color:' . esc_attr($color) . '">' . esc_html(License::status_label()) . '</strong>';
        if (License::is_unreachable()) {
            echo '<br><span class="description">' . esc_html__('No se puede contactar con SupuHub. Se mantiene el último estado válido durante 72 horas.', 'mis-resenas-de-google') . '</span>';
        }
        if ('' !== $state['reason'] && !License::is_valid()) {
            echo '<br><span class="description">' . esc_html($state['reason']) . '</span>';
        }
        echo '</td></tr>';

        if (License::has_key()) {
            echo '<tr><th>' . esc_html__('Clave', 'mis-resenas-de-google') . '</th><td><code>' . esc_html($state['key_masked']) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Caduca', 'mis-resenas-de-google') . '</th><td>' . esc_html('' !== $state['expires_at'] ? substr($state['expires_at'], 0, 10) : '—') . '</td></tr>';
            $used = null === $state['sites_used'] ? '—' : (string) (int) $state['sites_used'];
            $limit = null === $state['sites_limit'] ? '∞' : (string) (int) $state['sites_limit'];
            echo '<tr><th>' . esc_html__('Webs usadas', 'mis-resenas-de-google') . '</th><td>' . esc_html($used . ' / ' . $limit);
            if ('staging' === $state['environment']) {
                echo ' <span class="description">' . esc_html__('(esta web es de pruebas y no ocupa plaza)', 'mis-resenas-de-google') . '</span>';
            }
            echo '</td></tr>';
            if ('' !== $state['last_check_at']) {
                echo '<tr><th>' . esc_html__('Última comprobación', 'mis-resenas-de-google') . '</th><td>' . esc_html(get_date_from_gmt($state['last_check_at'], 'd/m/Y H:i')) . '</td></tr>';
            }
        }
        echo '</tbody></table>';

        // Formulario de licencia.
        $action = esc_url(admin_url('admin-post.php'));
        echo '<form method="post" action="' . $action . '" style="margin:16px 0;">';
        echo '<input type="hidden" name="action" value="mrg_license">';
        wp_nonce_field('mrg_license');

        if (!License::has_key()) {
            echo '<p><label for="mrg_license_key"><strong>' . esc_html__('Clave de licencia', 'mis-resenas-de-google') . '</strong></label><br>';
            echo '<input type="text" id="mrg_license_key" name="license_key" class="regular-text" autocomplete="off" spellcheck="false" placeholder="RESENAS-XXXX-XXXX-XXXX"></p>';
            echo '<p><button type="submit" name="op" value="activate" class="button button-primary">' . esc_html__('Activar', 'mis-resenas-de-google') . '</button> ';
            echo '<a class="button" href="' . esc_url(License::BUY_URL) . '" target="_blank" rel="noopener">' . esc_html__('Comprar licencia (49 €/año, 15 días de prueba)', 'mis-resenas-de-google') . '</a></p>';
        } else {
            echo '<p><button type="submit" name="op" value="check" class="button">' . esc_html__('Comprobar ahora', 'mis-resenas-de-google') . '</button> ';
            echo '<button type="submit" name="op" value="deactivate" class="button" onclick="return confirm(\'' . esc_js(__('¿Desactivar la licencia en esta web? Dejarás libre la plaza para usarla en otra.', 'mis-resenas-de-google')) . '\');">' . esc_html__('Desactivar', 'mis-resenas-de-google') . '</button>';
            if (!License::is_valid()) {
                echo ' <a class="button button-primary" href="' . esc_url(License::BUY_URL) . '" target="_blank" rel="noopener">' . esc_html__('Renovar o comprar', 'mis-resenas-de-google') . '</a>';
            }
            echo '</p>';
        }
        echo '</form>';

        $this->render_old_version_box();
        $this->render_data_box();

        echo '</div>';
    }

    private function render_old_version_box()
    {
        if (!Migration::old_folder_present()) {
            return;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = is_plugin_active(Migration::OLD_BASENAME) || is_plugin_active_for_network(Migration::OLD_BASENAME);

        echo '<div id="mrg-old-version" style="background:#fcf0f1;border-left:4px solid #d63638;padding:12px 16px;margin:24px 0;max-width:780px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__('Versión antigua instalada', 'mis-resenas-de-google') . '</h2>';
        echo '<p><strong>' . esc_html__('No borres la versión antigua desde Plugins: su desinstalador borra las reseñas.', 'mis-resenas-de-google') . '</strong></p>';
        echo '<p>' . esc_html__('Este botón quita la carpeta resenas_woo sin ejecutar su desinstalador. Tus reseñas, emails y ajustes se quedan como están.', 'mis-resenas-de-google') . '</p>';

        $blocked = Migration::removal_blocked_reason();
        if ('' !== $blocked) {
            echo '<p>' . esc_html($blocked) . '</p>';
        } elseif ($active) {
            echo '<p>' . esc_html__('La versión antigua sigue activa. Desactívala primero en Plugins (desactivar no borra nada).', 'mis-resenas-de-google') . '</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="mrg_remove_old">';
            wp_nonce_field('mrg_remove_old');
            echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__('Entiendo que se borra solo la carpeta de la versión antigua.', 'mis-resenas-de-google') . '</label></p>';
            echo '<p><button type="submit" class="button button-primary" onclick="return confirm(\'' . esc_js(__('¿Eliminar la versión antigua? Tus reseñas no se tocan.', 'mis-resenas-de-google')) . '\');">' . esc_html__('Eliminar la versión antigua de forma segura', 'mis-resenas-de-google') . '</button></p>';
            echo '</form>';
        }
        echo '</div>';
    }

    private function render_data_box()
    {
        $delete = (int) get_option(Migration::DELETE_DATA_OPTION, 0);

        echo '<h2>' . esc_html__('Desinstalación', 'mis-resenas-de-google') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mrg_data_settings">';
        wp_nonce_field('mrg_data_settings');
        echo '<p><label><input type="checkbox" name="delete_data" value="1" ' . checked(1, $delete, false) . '> ' . esc_html__('Borrar todos los datos al desinstalar', 'mis-resenas-de-google') . '</label><br>';
        echo '<span class="description">' . esc_html__('Desmarcado (recomendado), al borrar el plugin se conservan reseñas, historial y ajustes.', 'mis-resenas-de-google') . '</span></p>';
        submit_button(__('Guardar', 'mis-resenas-de-google'), 'secondary', 'submit', false);
        echo '</form>';
    }
}
