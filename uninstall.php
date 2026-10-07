<?php
/**
 * Desinstalación de Reseñas Woo 3.
 *
 * Por defecto NO borra datos: reseñas, historial de emails, ajustes y licencia
 * se conservan para poder reinstalar sin perder nada. Solo se borran si el
 * administrador marcó "Borrar todos los datos al desinstalar" (Reseñas Woo >
 * Licencia) y no queda otra copia del plugin que los use (la 2.x en la carpeta
 * resenas_woo/ comparte las mismas tablas y opciones).
 *
 * El installation_id de SupuHub se conserva SIEMPRE: reinstalar no debe ocupar
 * otra plaza de licencia ni reiniciar la prueba.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Las tareas programadas se quitan siempre (no son datos).
wp_clear_scheduled_hook('mrg_weekly_sync');
wp_unschedule_hook('mrg_weekly_sync_retry');
wp_clear_scheduled_hook('mrg_license_check');

$mrg_delete_data = 1 === (int) get_option('mrg_delete_data_on_uninstall', 0);

// ¿Queda otra copia del plugin (la versión antigua u otra carpeta con el mismo archivo)?
$mrg_other_copy = false;
if (defined('WP_PLUGIN_DIR')) {
    $mrg_own_dir = basename(__DIR__);
    foreach (['resenas_woo', 'resenas-woo'] as $mrg_dir) {
        if ($mrg_dir !== $mrg_own_dir && is_file(WP_PLUGIN_DIR . '/' . $mrg_dir . '/mis-resenas-de-google.php')) {
            $mrg_other_copy = true;
        }
    }
}

// Envíos de email pendientes (llevan [$order_id]): sin plugin no se enviarían y,
// al reinstalar meses después, saldrían a destiempo. Si queda otra copia, son suyos.
if (!$mrg_other_copy) {
    wp_unschedule_hook('mrg_send_scheduled_email');
}

if (!$mrg_delete_data || $mrg_other_copy) {
    return;
}

global $wpdb;

// 1. Tablas propias.
foreach ([$wpdb->prefix . 'mrg_reviews', $wpdb->prefix . 'mrg_email_logs'] as $mrg_table) {
    $wpdb->query("DROP TABLE IF EXISTS {$mrg_table}");
}

// 2. Opciones (el installation_id NO está en la lista a propósito).
foreach ([
    'mrg_settings',
    'mrg_version',
    'mrg_license',
    'mrg_update_cache',
    'mrg_auto_sync_last',
    'mrg_migrated_from_old',
    'mrg_delete_data_on_uninstall',
] as $mrg_option) {
    delete_option($mrg_option);
    delete_site_option($mrg_option);
}

// 3. Marca '_mrg_invitation_sent' de los pedidos (postmeta y tabla HPOS).
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

$mrg_hpos_meta = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mrg_hpos_meta)) === $mrg_hpos_meta) {
    $wpdb->query("DELETE FROM {$mrg_hpos_meta} WHERE meta_key = '_mrg_invitation_sent'");
}
