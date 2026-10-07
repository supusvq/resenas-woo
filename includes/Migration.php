<?php
/**
 * Convivencia y migración desde la versión antigua (2.x), instalada en la carpeta
 * wp-content/plugins/resenas_woo/. La 3.0 vive en resenas-woo/ y WordPress las
 * trata como dos plugins distintos que comparten tablas, opciones y clases.
 *
 * PELIGRO: el uninstall.php de la 2.x BORRA las tablas mrg_reviews y
 * mrg_email_logs y la opción mrg_settings. Si se borra la versión antigua desde
 * Plugins, se pierden todas las reseñas y el historial. Por eso aquí:
 *  - al activar la 3.0 se desactiva la antigua (sin tocar datos);
 *  - se ofrece un borrado seguro que quita la carpeta SIN ejecutar su desinstalador;
 *  - se quita el enlace "Borrar" de la antigua y se frena su desinstalación.
 *
 * Esta clase se carga también con require_once explícito desde el archivo
 * principal (cuando otra copia ya ha registrado su autoloader), así que no debe
 * depender de clases que la 2.x no tenga, salvo Activator.
 */

namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Migration
{
    const OLD_SLUG = 'resenas_woo';
    const OLD_BASENAME = 'resenas_woo/mis-resenas-de-google.php';
    const NEW_BASENAME = 'resenas-woo/mis-resenas-de-google.php';
    const DELETE_DATA_OPTION = 'mrg_delete_data_on_uninstall';

    /* ------------------------------------------------------------------ */
    /* Activación                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Gancho de activación de la 3.0. Funciona tanto si las clases las ha
     * cargado esta copia como si las cargó la 2.x (mismos nombres).
     */
    public static function on_activate()
    {
        // 1. Desactivar la antigua en silencio (sin su desactivador; no toca datos).
        if (function_exists('is_plugin_active') && function_exists('deactivate_plugins')) {
            if (is_plugin_active(self::OLD_BASENAME)) {
                deactivate_plugins(self::OLD_BASENAME, true);
                update_option('mrg_migrated_from_old', time(), false);
            }
            if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network(self::OLD_BASENAME)) {
                deactivate_plugins(self::OLD_BASENAME, true, true);
                update_option('mrg_migrated_from_old', time(), false);
            }
        }

        // 2. Tablas y ajustes: mismos nombres que la 2.x. dbDelta y add_option no
        //    pisan nada existente: no hay nada que migrar.
        Activator::activate();

        // 3. Programación limpia de la importación semanal (la 2.x pudo dejar
        //    eventos y reintentos con el mismo nombre).
        wp_clear_scheduled_hook('mrg_weekly_sync');
        wp_unschedule_hook('mrg_weekly_sync_retry');
        $first = new \DateTimeImmutable('next monday 09:00', wp_timezone());
        wp_schedule_event($first->getTimestamp(), 'weekly', 'mrg_weekly_sync');
    }

    /* ------------------------------------------------------------------ */
    /* Detección                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * True si la versión antigua está marcada como activa (sitio o red) y su
     * archivo existe. Lo usa el archivo principal antes de cargar nada.
     *
     * @param array  $active_plugins   Opción active_plugins.
     * @param array  $network_plugins  Opción de red active_sitewide_plugins (claves).
     * @param string $plugins_dir      WP_PLUGIN_DIR.
     * @param string $own_basename     Basename de esta copia.
     */
    public static function old_is_active(array $active_plugins, array $network_plugins, $plugins_dir, $own_basename)
    {
        if (self::OLD_BASENAME === $own_basename) {
            return false; // Esta copia ES la carpeta antigua.
        }
        $listed = in_array(self::OLD_BASENAME, $active_plugins, true) || isset($network_plugins[self::OLD_BASENAME]);

        return $listed && is_file(rtrim((string) $plugins_dir, '/\\') . '/' . self::OLD_BASENAME);
    }

    /**
     * True si existe la carpeta de la versión antigua (y no es esta misma copia).
     */
    public static function old_folder_present()
    {
        if (!defined('WP_PLUGIN_DIR') || self::OLD_BASENAME === MRG_BASENAME) {
            return false;
        }

        return is_dir(WP_PLUGIN_DIR . '/' . self::OLD_SLUG);
    }

    /* ------------------------------------------------------------------ */
    /* Borrado seguro                                                     */
    /* ------------------------------------------------------------------ */

    private static function normalize($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }

    /**
     * Valida que $dir es exactamente la carpeta de la versión antigua y que se
     * puede borrar. Devuelve '' si todo está bien o el motivo en español.
     *
     * @param string $dir         Carpeta que se quiere borrar.
     * @param string $plugins_dir WP_PLUGIN_DIR.
     * @param string $own_dir     Carpeta de esta copia (MRG_PATH).
     * @param bool   $old_active  Si la versión antigua sigue activa.
     */
    public static function validate_old_dir($dir, $plugins_dir, $own_dir, $old_active)
    {
        $expected = self::normalize($plugins_dir) . '/' . self::OLD_SLUG;
        $dir_n = self::normalize($dir);

        if ('' === self::normalize($plugins_dir) || $dir_n !== $expected) {
            return __('Ruta no permitida: solo se puede borrar la carpeta de la versión antigua.', 'mis-resenas-de-google');
        }
        if (false !== strpos($dir_n, '/../') || '/..' === substr($dir_n, -3)) {
            return __('Ruta no permitida.', 'mis-resenas-de-google');
        }
        if (is_link($dir)) {
            return __('La carpeta antigua es un enlace simbólico; bórrala a mano.', 'mis-resenas-de-google');
        }
        if (!is_dir($dir)) {
            return __('La versión antigua ya no está instalada.', 'mis-resenas-de-google');
        }

        $real_dir = realpath($dir);
        $real_plugins = realpath($plugins_dir);
        if (false === $real_dir || false === $real_plugins
            || self::normalize($real_dir) !== self::normalize($real_plugins) . '/' . self::OLD_SLUG) {
            return __('Ruta no permitida: la carpeta no está donde se esperaba.', 'mis-resenas-de-google');
        }

        $own_real = realpath((string) $own_dir);
        if (false !== $own_real && self::normalize($own_real) === self::normalize($real_dir)) {
            return __('Esta copia de Reseñas Woo está instalada en esa carpeta: no se puede borrar a sí misma.', 'mis-resenas-de-google');
        }

        if (!is_file($real_dir . '/mis-resenas-de-google.php')) {
            return __('La carpeta no contiene la versión antigua de Reseñas Woo.', 'mis-resenas-de-google');
        }
        if ($old_active) {
            return __('Desactiva primero la versión antigua.', 'mis-resenas-de-google');
        }

        return '';
    }

    /**
     * Borra la carpeta antigua con WP_Filesystem, sin ejecutar su uninstall.php.
     *
     * @throws \RuntimeException Con el motivo si no se puede.
     */
    public static function delete_old_folder()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $dir = WP_PLUGIN_DIR . '/' . self::OLD_SLUG;
        $active = is_plugin_active(self::OLD_BASENAME) || is_plugin_active_for_network(self::OLD_BASENAME);
        $error = self::validate_old_dir($dir, WP_PLUGIN_DIR, MRG_PATH, $active);
        if ('' !== $error) {
            throw new \RuntimeException($error);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (!WP_Filesystem() || !is_object($wp_filesystem)) {
            throw new \RuntimeException(__('WordPress no puede borrar archivos en este servidor. Borra por FTP la carpeta wp-content/plugins/resenas_woo (no uses el botón Borrar de Plugins).', 'mis-resenas-de-google'));
        }

        if (!$wp_filesystem->delete(realpath($dir), true, 'd')) {
            throw new \RuntimeException(__('No se pudo borrar la carpeta antigua. Bórrala por FTP: wp-content/plugins/resenas_woo.', 'mis-resenas-de-google'));
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Protección en la pantalla Plugins                                  */
    /* ------------------------------------------------------------------ */

    public static function hooks()
    {
        add_filter('plugin_action_links_' . self::OLD_BASENAME, [__CLASS__, 'old_action_links']);
        add_action('pre_uninstall_plugin', [__CLASS__, 'block_old_uninstall']);
    }

    /**
     * Cambia el enlace "Borrar" de la versión antigua por el borrado seguro.
     */
    public static function old_action_links($actions)
    {
        if (self::OLD_BASENAME === MRG_BASENAME) {
            return $actions;
        }
        unset($actions['delete']);
        $actions['mrg_safe_delete'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=mrg-license#mrg-old-version')),
            esc_html__('Borrar de forma segura', 'mis-resenas-de-google')
        );

        return $actions;
    }

    /**
     * Frena la desinstalación de la 2.x desde Plugins: su uninstall.php borraría
     * las reseñas que usa esta versión.
     */
    public static function block_old_uninstall($plugin)
    {
        if (self::OLD_BASENAME !== $plugin || self::OLD_BASENAME === MRG_BASENAME) {
            return;
        }

        wp_die(
            esc_html__('No borres la versión antigua de Reseñas Woo desde Plugins: su desinstalador borra las reseñas y el historial que usa la versión nueva. Usa "Eliminar la versión antigua de forma segura" en Reseñas Woo > Licencia.', 'mis-resenas-de-google'),
            esc_html__('Borrado bloqueado', 'mis-resenas-de-google'),
            ['response' => 403, 'back_link' => true]
        );
    }
}
