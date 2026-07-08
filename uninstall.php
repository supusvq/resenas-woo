<?php
/**
 * Fichero disparado al desinstalar el plugin.
 * 
 * Elimina permanentemente todos los datos creados por el plugin:
 * - Tablas personalizadas (reviews y logs).
 * - Opciones de WordPress (settings y versión).
 * - Metadatos de pedidos de WooCommerce.
 */

// Si no es llamado por WordPress, morir.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. ELIMINAR TABLAS PERSONALIZADAS
$tables = [
    $wpdb->prefix . 'mrg_reviews',
    $wpdb->prefix . 'mrg_email_logs',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// 2. ELIMINAR OPCIONES
$options = [
    'mrg_settings',
    'mrg_version',
];

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option); // Por si acaso en instalaciones multisite
}

// 3. ELIMINAR METADATOS DE CUALQUIER PEDIDO DE WOOCOMMERCE
// Limpieza de la marca '_mrg_invitation_sent' que indica si ya se invito a un cliente.
// Se limpia tanto en postmeta (almacenamiento legacy) como en la tabla de meta de HPOS,
// porque ambas pueden tener datos segun el modo de almacenamiento usado en la vida del sitio.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
    $wpdb->query("DELETE FROM {$hpos_meta_table} WHERE meta_key = '_mrg_invitation_sent'");
}

// 4. Limpiar eventos programados. wp_clear_scheduled_hook() sin argumentos solo
// desprograma eventos que se programaron SIN argumentos: los reales llevan [$order_id],
// asi que quedaban huerfanos. wp_unschedule_hook() elimina TODOS los eventos del hook
// sin importar sus argumentos (WP 5.1+; este plugin requiere WP 6.0+).
wp_unschedule_hook('mrg_send_scheduled_email');
