<?php
/**
 * Plugin Name: Reseñas Woo
 * Plugin URI: https://www.supudigital.es
 * Description: Visualiza reseñas de Google almacenadas localmente y automatiza solicitudes de reseña post-compra en WooCommerce.
 * Version: 2.12.3
 * Author: Juan Gallardo
 * Author URI: https://www.supudigital.es
 * Text Domain: mis-resenas-de-google
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MRG_VERSION', '2.12.3');
define('MRG_FILE', __FILE__);
define('MRG_PATH', plugin_dir_path(__FILE__));
define('MRG_URL', plugin_dir_url(__FILE__));
define('MRG_BASENAME', plugin_basename(__FILE__));

require_once MRG_PATH . 'includes/Autoloader.php';
\MRG\Autoloader::register();

register_activation_hook(__FILE__, ['MRG\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['MRG\\Deactivator', 'deactivate']);

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

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

    if (class_exists('MRG\\Privacy')) {
        (new MRG\Privacy())->init();
    }

    if (class_exists('WooCommerce')) {
        (new MRG\WooCommerce\OrderHooks())->init();
    }

    // Hook para envíos programados (Cron)
    add_action('mrg_send_scheduled_email', function ($order_id) {
        (new MRG\Emails\EmailScheduler())->send_now($order_id);
    });

    // Importación automática de reseñas de Google una vez por semana (lunes a las 9:00, hora de la web).
    // Si falla (servicio ocupado, límite, red…), reintenta hasta 3 veces cada 2 horas.
    $mrg_auto_sync = function ($attempt = 0) {
        $attempt = (int) $attempt;
        $result  = (new MRG\Reviews\ReviewSyncService())->sync();
        update_option('mrg_auto_sync_last', ['time' => time(), 'attempt' => $attempt, 'result' => $result], false);
        if (isset($result['error']) && $attempt < 3 && !wp_next_scheduled('mrg_weekly_sync_retry', [$attempt + 1])) {
            wp_schedule_single_event(time() + 2 * HOUR_IN_SECONDS, 'mrg_weekly_sync_retry', [$attempt + 1]);
        }
    };
    add_action('mrg_weekly_sync', function () use ($mrg_auto_sync) {
        $mrg_auto_sync(0);
    });
    add_action('mrg_weekly_sync_retry', $mrg_auto_sync);
    if (!wp_next_scheduled('mrg_weekly_sync')) {
        $first = new DateTimeImmutable('next monday 09:00', wp_timezone());
        wp_schedule_event($first->getTimestamp(), 'weekly', 'mrg_weekly_sync');
    }
});
