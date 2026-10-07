<?php
/**
 * Convivencia y migración desde la versión antigua (2.x), instalada en la carpeta
 * wp-content/plugins/resenas_woo/. La 3.0 vive en resenas-woo/ y WordPress las
 * trata como dos plugins distintos que comparten tablas, opciones y clases.
 *
 * PELIGRO: el uninstall.php de la 2.x BORRA las tablas mrg_reviews y
 * mrg_email_logs y la opción mrg_settings. Si se borra la versión antigua desde
 * Plugins, se pierden todas las reseñas y el historial. Por eso aquí:
 *  - al activar la 3.0 (y en cada admin_init mientras exista la carpeta antigua)
 *    se NEUTRALIZA el uninstall.php antiguo: se sustituye por un archivo que no
 *    hace nada. Así la protección sigue aunque la 3.0 se desactive después;
 *  - al activar la 3.0 se desactiva la antigua (sin tocar datos);
 *  - se ofrece un borrado seguro que quita la carpeta sin seguir enlaces;
 *  - se quita el enlace "Borrar" de la antigua y se frena su desinstalación.
 *
 * WordPress (uninstall_plugin() en wp-admin/includes/plugin.php) ejecuta
 * uninstall.php si el archivo existe y, solo si no existe, el callback de
 * register_uninstall_hook guardado en la opción uninstall_plugins. La 2.x no
 * registra ese callback, así que neutralizar uninstall.php basta.
 *
 * Esta clase se carga también con require_once explícito desde el archivo
 * principal (cuando la 2.x ya ha cargado y definido MRG_*), así que no usa las
 * constantes MRG_*: su propia carpeta sale de __DIR__.
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
    const UNINSTALL_ERROR_OPTION = 'mrg_old_uninstall_error';

    // Marca del uninstall.php neutralizado (para no reescribirlo en cada carga).
    const STUB_MARKER = 'MRG-UNINSTALL-NEUTRALIZADO';

    /**
     * Contenido que sustituye al uninstall.php de la 2.x. Usa "return" y no
     * "exit": uninstall_plugin() lo incluye dentro de la petición de borrado y un
     * exit cortaría el borrado de archivos a medias.
     */
    const STUB = "<?php\n/**\n * MRG-UNINSTALL-NEUTRALIZADO\n *\n * Reseñas Woo 3 ha sustituido el desinstalador de la versión 2.x porque borraba\n * las tablas y ajustes que comparte con la versión nueva. Borrar esta carpeta\n * ya no elimina reseñas, historial ni ajustes.\n */\nreturn;\n";

    /**
     * Carpeta de ESTA copia (la que contiene includes/Migration.php).
     */
    public static function own_dir()
    {
        return dirname(__DIR__);
    }

    private static function is_old_copy($own_dir = null)
    {
        $own_dir = null === $own_dir ? self::own_dir() : $own_dir;

        return self::OLD_SLUG === basename(self::normalize($own_dir));
    }

    /* ------------------------------------------------------------------ */
    /* Activación                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Gancho de activación de la 3.0. Funciona tanto si las clases las ha
     * cargado esta copia como si las cargó la 2.x (mismos nombres).
     */
    public static function on_activate()
    {
        // 0. Antes que nada, desarmar el desinstalador de la 2.x.
        self::maybe_neutralize_old_uninstall();

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
    /* Neutralizar el desinstalador de la 2.x                             */
    /* ------------------------------------------------------------------ */

    /**
     * Sustituye WP_PLUGIN_DIR/resenas_woo/uninstall.php por STUB.
     * Devuelve '' si queda neutralizado (o no hay nada que hacer) o el motivo.
     *
     * @param string|null $plugins_dir WP_PLUGIN_DIR (parámetro para pruebas).
     * @param string|null $own_dir     Carpeta de esta copia (parámetro para pruebas).
     */
    public static function neutralize_old_uninstall($plugins_dir = null, $own_dir = null)
    {
        $plugins_dir = null === $plugins_dir ? (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '') : $plugins_dir;
        $own_dir = null === $own_dir ? self::own_dir() : $own_dir;

        if ('' === self::normalize($plugins_dir) || self::is_old_copy($own_dir)) {
            return '';
        }

        $dir = self::normalize($plugins_dir) . '/' . self::OLD_SLUG;
        $file = $dir . '/uninstall.php';

        if (!file_exists($file) && !is_link($file)) {
            return ''; // Sin carpeta antigua o sin desinstalador: nada que desarmar.
        }
        if (is_link($dir) || is_link($file)) {
            return __('El desinstalador de la versión antigua es un enlace simbólico y no se ha tocado.', 'mis-resenas-de-google');
        }

        $real_file = realpath($file);
        $real_plugins = realpath($plugins_dir);
        if (false === $real_file || false === $real_plugins
            || self::normalize($real_file) !== self::normalize($real_plugins) . '/' . self::OLD_SLUG . '/uninstall.php') {
            return __('El desinstalador de la versión antigua no está donde se esperaba y no se ha tocado.', 'mis-resenas-de-google');
        }

        $own_real = realpath($own_dir);
        if (false !== $own_real && 0 === strpos(self::normalize($real_file) . '/', self::normalize($own_real) . '/')) {
            return ''; // Sería el desinstalador de esta misma copia.
        }

        if (false !== strpos((string) @file_get_contents($real_file), self::STUB_MARKER)) {
            return ''; // Ya neutralizado.
        }

        if (!is_writable($real_file) || false === @file_put_contents($real_file, self::STUB, LOCK_EX)) {
            return __('No se ha podido desarmar el desinstalador de la versión antigua (permisos). No la borres desde Plugins: borraría las reseñas.', 'mis-resenas-de-google');
        }

        clearstatcache(true, $real_file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($real_file, true);
        }

        return false !== strpos((string) file_get_contents($real_file), self::STUB_MARKER)
            ? ''
            : __('No se ha podido comprobar el desinstalador de la versión antigua.', 'mis-resenas-de-google');
    }

    /**
     * Neutraliza y guarda el resultado para el aviso del admin. Nunca lanza.
     */
    public static function maybe_neutralize_old_uninstall()
    {
        $error = self::neutralize_old_uninstall();

        if ('' === $error) {
            delete_option(self::UNINSTALL_ERROR_OPTION);
        } else {
            update_option(self::UNINSTALL_ERROR_OPTION, $error, false);
            if (function_exists('error_log')) {
                error_log('[Reseñas Woo] ' . $error);
            }
        }

        return $error;
    }

    /* ------------------------------------------------------------------ */
    /* Detección                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * True si la versión antigua está marcada como activa (sitio o red) y su
     * archivo existe. Lo usa el archivo principal antes de cargar nada.
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
        if (!defined('WP_PLUGIN_DIR') || self::is_old_copy()) {
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
     * Motivo por el que el usuario actual no puede borrar la carpeta antigua,
     * o '' si puede.
     *
     * En multisitio se desactiva siempre: comprobar que la 2.x no está activa en
     * ningún sitio de la red no compensa el riesgo, y su desinstalador ya está
     * neutralizado, así que dejar la carpeta no pone datos en peligro.
     */
    public static function removal_blocked_reason()
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return __('En una instalación multisitio el borrado desde aquí está desactivado. Pide a quien administra la red que borre la carpeta wp-content/plugins/resenas_woo por FTP cuando la versión antigua no esté activa en ningún sitio.', 'mis-resenas-de-google');
        }
        if (!current_user_can('delete_plugins')) {
            return __('Tu usuario no puede borrar plugins en esta web (o la edición de archivos está bloqueada con DISALLOW_FILE_MODS). Borra la carpeta wp-content/plugins/resenas_woo por FTP.', 'mis-resenas-de-google');
        }

        return '';
    }

    /**
     * Valida que $dir es exactamente la carpeta de la versión antigua y que se
     * puede borrar. Devuelve '' si todo está bien o el motivo en español.
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
     * True si $path es un enlace simbólico o algo que se comporta como tal. En
     * Windows is_link() no detecta las uniones (junctions): se compara además la
     * ruta real con la del padre + el nombre; si no coinciden, apunta a otro sitio.
     */
    public static function is_link_like($path)
    {
        if (is_link($path)) {
            return true;
        }
        if (!file_exists($path)) {
            return false;
        }
        $real = realpath($path);
        $parent = realpath(dirname($path));
        if (false === $real || false === $parent) {
            return true; // Ante la duda, no se entra.
        }
        $expected = self::normalize($parent) . '/' . basename(self::normalize($path));
        $real = self::normalize($real);
        if ('\\' === DIRECTORY_SEPARATOR) {
            return strtolower($real) !== strtolower($expected);
        }

        return $real !== $expected;
    }

    /**
     * Borrado recursivo que NUNCA sigue enlaces: un enlace simbólico (o unión de
     * Windows) se quita a sí mismo, sin entrar en lo que apunta.
     *
     * @param string        $path    Ruta a borrar.
     * @param callable|null $is_link Detector de enlaces (sustituible en pruebas).
     * @return bool True si se borró todo.
     */
    public static function delete_tree($path, $is_link = null)
    {
        $is_link = $is_link ?: [__CLASS__, 'is_link_like'];

        if (call_user_func($is_link, $path)) {
            // En Windows un enlace a carpeta se quita con rmdir, no con unlink.
            return @unlink($path) || @rmdir($path);
        }
        if (is_dir($path)) {
            $entries = @scandir($path);
            if (false === $entries) {
                return false;
            }
            $ok = true;
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $ok = self::delete_tree($path . '/' . $entry, $is_link) && $ok;
            }
            return $ok && @rmdir($path);
        }
        if (file_exists($path)) {
            return @unlink($path);
        }

        return true;
    }

    /**
     * Borra la carpeta antigua sin ejecutar su uninstall.php y sin seguir enlaces.
     *
     * @throws \RuntimeException Con el motivo si no se puede.
     */
    public static function delete_old_folder()
    {
        $blocked = self::removal_blocked_reason();
        if ('' !== $blocked) {
            throw new \RuntimeException($blocked);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $dir = WP_PLUGIN_DIR . '/' . self::OLD_SLUG;
        $active = is_plugin_active(self::OLD_BASENAME) || is_plugin_active_for_network(self::OLD_BASENAME);
        $error = self::validate_old_dir($dir, WP_PLUGIN_DIR, self::own_dir(), $active);
        if ('' !== $error) {
            throw new \RuntimeException($error);
        }

        if (!self::delete_tree(realpath($dir))) {
            throw new \RuntimeException(__('No se pudo borrar del todo la carpeta antigua. Termina de borrarla por FTP: wp-content/plugins/resenas_woo (no uses el botón Borrar de Plugins).', 'mis-resenas-de-google'));
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
        add_action('admin_init', [__CLASS__, 'admin_init']);
    }

    /**
     * Mientras exista la carpeta antigua, se asegura de que su desinstalador está
     * desarmado (por si se reinstaló la 2.x encima).
     */
    public static function admin_init()
    {
        if (self::old_folder_present()) {
            self::maybe_neutralize_old_uninstall();
        } elseif (false !== get_option(self::UNINSTALL_ERROR_OPTION, false)) {
            delete_option(self::UNINSTALL_ERROR_OPTION);
        }
    }

    /**
     * Cambia el enlace "Borrar" de la versión antigua por el borrado seguro.
     */
    public static function old_action_links($actions)
    {
        if (self::is_old_copy()) {
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
     * Frena la desinstalación de la 2.x desde Plugins mientras la 3.0 esté
     * activa. Es una segunda barrera: la primera es el uninstall.php neutralizado.
     */
    public static function block_old_uninstall($plugin)
    {
        if (self::OLD_BASENAME !== $plugin || self::is_old_copy()) {
            return;
        }

        wp_die(
            esc_html__('No borres la versión antigua de Reseñas Woo desde Plugins: su desinstalador borra las reseñas y el historial que usa la versión nueva. Usa "Eliminar la versión antigua de forma segura" en Reseñas Woo > Licencia.', 'mis-resenas-de-google'),
            esc_html__('Borrado bloqueado', 'mis-resenas-de-google'),
            ['response' => 403, 'back_link' => true]
        );
    }
}
