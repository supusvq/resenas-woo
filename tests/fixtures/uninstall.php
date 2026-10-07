<?php
/**
 * Proceso hijo de tests/migration.php: ejecuta el uninstall.php de la 3.0
 * (copiado en <tmp>/plugins/resenas-woo/) con unas opciones dadas y devuelve
 * en JSON las consultas SQL, las opciones que quedan y el cron.
 *
 * Uso: php uninstall.php <carpeta-temporal> <opciones-json-base64>
 */

$tmp = rtrim(str_replace('\\', '/', $argv[1]), '/');
define('ABSPATH', $tmp . '/wp/');
define('WP_PLUGIN_DIR', $tmp . '/plugins');
define('WP_UNINSTALL_PLUGIN', 'resenas-woo/mis-resenas-de-google.php');

require dirname(__DIR__) . '/bootstrap.php';

$GLOBALS['options'] = json_decode(base64_decode($argv[2]), true);
$GLOBALS['cron'] = [
    ['hook' => 'mrg_weekly_sync', 'time' => 1, 'recurrence' => 'weekly', 'args' => []],
    ['hook' => 'mrg_license_check', 'time' => 1, 'recurrence' => 'hourly', 'args' => []],
    ['hook' => 'mrg_send_scheduled_email', 'time' => 1, 'recurrence' => false, 'args' => [55]],
];

class TestWpdb
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $queries = [];
    public function prepare($q, ...$a) { return $q; }
    public function query($q) { $this->queries[] = $q; return true; }
    public function get_var($q) { $this->queries[] = $q; return null; }
}
$GLOBALS['wpdb'] = new TestWpdb();

(function () use ($tmp) {
    include $tmp . '/plugins/resenas-woo/uninstall.php';
})();

echo json_encode([
    'queries' => $GLOBALS['wpdb']->queries,
    'options' => $GLOBALS['options'],
    'cron' => array_map(function ($e) { return $e['hook']; }, $GLOBALS['cron']),
]);
