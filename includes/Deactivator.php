<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Deactivator {
    public static function deactivate() {
        // Sin limpieza de datos; solo se quita la importación semanal programada.
        wp_clear_scheduled_hook('mrg_weekly_sync');
    }
}
