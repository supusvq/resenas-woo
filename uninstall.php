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
// Limpieza de la marca '_mrg_invitation_sent' que indica si ya se invitó a un cliente
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mrg_invitation_sent'");

// 4. OPCIONAL: Limpiar eventos programados (aunque WP suele manejarlos, forzamos)
wp_clear_scheduled_hook('mrg_send_scheduled_email');
