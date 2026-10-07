<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        // Sin limpieza de datos; solo se quitan las tareas programadas
        // (importación semanal, sus reintentos y la revalidación de la licencia).
        wp_clear_scheduled_hook('mrg_weekly_sync');
        wp_unschedule_hook('mrg_weekly_sync_retry');
        wp_clear_scheduled_hook('mrg_license_check');
    }
}
