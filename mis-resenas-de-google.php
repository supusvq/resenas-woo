<?php
/**
 * Plugin Name: Reseñas Woo
 * Plugin URI: https://supudigital.es/resenas-woo/
 * Description: Visualiza reseñas de Google almacenadas localmente y automatiza solicitudes de reseña post-compra en WooCommerce.
 * Version: 3.0.0
 * Author: Juan Gallardo by SupuDigital
 * Author URI: https://www.supudigital.es
 * Text Domain: mis-resenas-de-google
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Update URI: https://api.supudigital.es/products/resenaswoo
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Convivencia con otra copia (la 2.x en la carpeta resenas_woo/ u otra copia de la 3.x).
 * Comparten clases MRG\... y constantes MRG_*: cargar dos veces daría avisos y
 * ganchos duplicados (emails repetidos). Si otra copia ya está cargada, o la versión
 * antigua sigue activa (WordPress carga resenas-woo/ antes que resenas_woo/ porque
 * '-' va antes que '_'), esta copia se queda en reposo: solo avisa y deja registrado
 * su gancho de activación, que desactiva la antigua sin tocar datos.
 */
$mrg_other_copy = defined('MRG_VERSION') || class_exists('MRG\\Autoloader', false);
$mrg_network = [];
if (!$mrg_other_copy) {
    if (!class_exists('MRG\\Migration', false)) {
        require_once __DIR__ . '/includes/Migration.php';
    }
    if (function_exists('is_multisite') && is_multisite()) {
        $mrg_network = (array) get_site_option('active_sitewide_plugins', []);
    }
    $mrg_other_copy = defined('WP_PLUGIN_DIR') && \MRG\Migration::old_is_active(
        (array) get_option('active_plugins', []),
        $mrg_network,
        WP_PLUGIN_DIR,
        plugin_basename(__FILE__)
    );
}

if ($mrg_other_copy) {
    if (!class_exists('MRG\\Migration', false)) {
        require_once __DIR__ . '/includes/Migration.php';
    }
    register_activation_hook(__FILE__, ['MRG\\Migration', 'on_activate']);
    add_action('admin_notices', 'mrg_other_copy_notice');
    if (!function_exists('mrg_other_copy_notice')) {
        function mrg_other_copy_notice()
        {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-warning"><p><strong>Reseñas Woo 3:</strong> '
                . esc_html__('hay otra versión de Reseñas Woo activa y la nueva está en espera. Desactiva la versión antigua en Plugins (desactivar no borra nada). No la borres desde Plugins: su desinstalador borra las reseñas.', 'mis-resenas-de-google')
                . '</p></div>';
        }
    }
    unset($mrg_other_copy, $mrg_network);
    return;
}
unset($mrg_other_copy, $mrg_network);

define('MRG_VERSION', '3.0.0');
define('MRG_FILE', __FILE__);
define('MRG_PATH', plugin_dir_path(__FILE__));
define('MRG_URL', plugin_dir_url(__FILE__));
define('MRG_BASENAME', plugin_basename(__FILE__));

require_once MRG_PATH . 'includes/Autoloader.php';
\MRG\Autoloader::register();

register_activation_hook(__FILE__, ['MRG\\Migration', 'on_activate']);
register_deactivation_hook(__FILE__, ['MRG\\Deactivator', 'deactivate']);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Licencia y actualizaciones de SupuHub: en todas las peticiones (WP-Cron incluido).
\MRG\License\License::hooks();
\MRG\License\Updater::hooks();
\MRG\Migration::hooks();

add_action('plugins_loaded', function () {
    load_plugin_textdomain('mis-resenas-de-google', false, dirname(MRG_BASENAME) . '/languages');

    if (is_admin()) {
        \MRG\Database::maybe_upgrade();
        (new MRG\Admin\Menu())->init();
        (new MRG\Admin\Settings())->init();
        (new MRG\Admin\ReviewsPage())->init();
        (new MRG\Admin\EmailsPage())->init();
        (new MRG\Admin\LogsPage())->init();
        (new MRG\Admin\InvitationsPage())->init();
        (new MRG\Admin\LicensePage())->init();
    }

    (new MRG\Frontend\Shortcode())->init();

    if (class_exists('MRG\\Privacy')) {
        (new MRG\Privacy())->init();
    }

    if (class_exists('WooCommerce')) {
        (new MRG\WooCommerce\OrderHooks())->init();
    }

    // Hook para envíos programados (Cron). No depende de la licencia.
    add_action('mrg_send_scheduled_email', function ($order_id) {
        (new MRG\Emails\EmailScheduler())->send_now($order_id);
    });

    // Importación automática semanal con reintentos (exige licencia; ver AutoSync).
    \MRG\Reviews\AutoSync::hooks();
});
