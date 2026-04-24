<?php
/**
 * Plugin Name: Reseñas Woo
 * Plugin URI: https://www.supudigital.es
 * Description: Visualiza reseñas de Google almacenadas localmente y automatiza solicitudes de reseña post-compra en WooCommerce.
 * Version: 2.10.6
 * Author: Juan Gallardo
 * Author URI: https://www.supudigital.es
 * Text Domain: mis-resenas-de-google
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MRG_VERSION', '2.10.6');
define('MRG_FILE', __FILE__);
define('MRG_PATH', plugin_dir_path(__FILE__));
define('MRG_URL', plugin_dir_url(__FILE__));
define('MRG_BASENAME', plugin_basename(__FILE__));

require_once MRG_PATH . 'includes/Autoloader.php';
\MRG\Autoloader::register();

register_activation_hook(__FILE__, ['MRG\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MRG\\Deactivator', 'deactivate']);

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
    }

    (new MRG\Frontend\Shortcode())->init();
    (new MRG\Privacy())->init();

    if (class_exists('WooCommerce')) {
        (new MRG\WooCommerce\OrderHooks())->init();
    }

    // Hook para envíos programados (Cron)
    add_action('mrg_send_scheduled_email', function ($order_id) {
        (new MRG\Emails\EmailScheduler())->send_now($order_id);
    });
});
