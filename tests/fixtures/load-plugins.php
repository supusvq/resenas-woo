<?php
/**
 * Proceso hijo de tests/migration.php: simula cómo WordPress carga (o activa)
 * la 3.0 y la 2.x en distintos órdenes y devuelve en JSON lo que ha pasado.
 * Cada escenario va en su propio proceso porque las constantes no se pueden
 * deshacer.
 *
 * Uso: php load-plugins.php <escenario> <carpeta-temporal>
 */

$scenario = $argv[1];
$tmp = rtrim(str_replace('\\', '/', $argv[2]), '/');

define('ABSPATH', $tmp . '/wp/');
define('WP_PLUGIN_DIR', $tmp . '/plugins');

require dirname(__DIR__) . '/bootstrap.php';

$NEW_MAIN = dirname(__DIR__, 2) . '/mis-resenas-de-google.php';
$OLD_MAIN = WP_PLUGIN_DIR . '/resenas_woo/mis-resenas-de-google.php';
$NEW = 'resenas-woo/mis-resenas-de-google.php';
$OLD = 'resenas_woo/mis-resenas-de-google.php';

// plugin_basename según la carpeta real del archivo.
function plugin_basename_for($file)
{
    return strpos(str_replace('\\', '/', $file), '/plugins/resenas_woo/') !== false
        ? 'resenas_woo/mis-resenas-de-google.php'
        : 'resenas-woo/mis-resenas-de-google.php';
}
$GLOBALS['plugin_basename'] = null;

function get_home_url() { return 'https://cliente.es'; }
function is_admin() { return false; }

class TestWpdb
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $queries = [];
    public function get_charset_collate() { return ''; }
    public function prepare($q, ...$a) { return $q; }
    public function query($q) { $this->queries[] = $q; return true; }
    public function get_results($q) { $this->queries[] = $q; return []; }
    public function get_var($q) { $this->queries[] = $q; return null; }
}
$GLOBALS['wpdb'] = new TestWpdb();

$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    $warnings[] = basename(dirname($file)) . '/' . basename($file) . ":$line $str";
    return true;
});

$result = ['fatal' => null];
register_shutdown_function(function () use (&$result, &$warnings) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $result['fatal'] = $err['message'];
    }
    $result['warnings'] = $warnings;
    $result['MRG_VERSION'] = defined('MRG_VERSION') ? MRG_VERSION : null;
    $result['MRG_PATH'] = defined('MRG_PATH') ? MRG_PATH : null;
    $result['plugins_loaded'] = count($GLOBALS['actions']['plugins_loaded'] ?? []);
    $result['other_copy_notice'] = in_array('mrg_other_copy_notice', $GLOBALS['actions']['admin_notices'] ?? [], true);
    $result['activation_hooks'] = array_map(function ($h) {
        return [plugin_basename_for($h['file']), is_array($h['callback']) ? implode('::', $h['callback']) : 'closure'];
    }, $GLOBALS['activation_hooks']);
    $result['update_filter'] = !empty($GLOBALS['actions']['update_plugins_api.supudigital.es']);
    $result['active_plugins'] = get_option('active_plugins', []);
    $result['deactivated'] = $GLOBALS['deactivated'];
    $result['cron'] = array_map(function ($e) { return $e['hook']; }, $GLOBALS['cron']);
    $result['drops'] = count(array_filter($GLOBALS['wpdb']->queries, function ($q) { return stripos($q, 'DROP') !== false; }));
    $result['dbdelta'] = $GLOBALS['dbdelta'] ?? 0;
    echo json_encode($result);
});

// Carga de un plugin como lo hace WordPress (include dentro de una función).
function load_plugin($file)
{
    $GLOBALS['plugin_basename'] = plugin_basename_for($file);
    include_once $file;
}

// plugin_basename del bootstrap devuelve $GLOBALS['plugin_basename'].
switch ($scenario) {
    case 'predefined':
        define('MRG_VERSION', 'otra-copia');
        update_option('active_plugins', [$NEW]);
        load_plugin($NEW_MAIN);
        break;

    case 'clean':
        update_option('active_plugins', [$NEW]);
        load_plugin($NEW_MAIN);
        break;

    case 'old_present_inactive':
        update_option('active_plugins', [$NEW]);
        load_plugin($NEW_MAIN);
        break;

    case 'both_active_wp_order':
        // Orden real de active_plugins tras sort(): resenas-woo antes que resenas_woo.
        $list = [$OLD, $NEW];
        sort($list);
        update_option('active_plugins', $list);
        foreach ($list as $p) {
            load_plugin($p === $NEW ? $NEW_MAIN : $OLD_MAIN);
        }
        $result['load_order'] = $list;
        break;

    case 'both_active_old_first':
        update_option('active_plugins', [$OLD, $NEW]);
        load_plugin($OLD_MAIN);
        load_plugin($NEW_MAIN);
        break;

    case 'activate_new_while_old_active':
        // Petición de activación: la 2.x ya está cargada; WordPress incluye la 3.0 y dispara su gancho.
        update_option('active_plugins', [$OLD]);
        $GLOBALS['cron'][] = ['hook' => 'mrg_weekly_sync', 'time' => 1, 'recurrence' => 'weekly', 'args' => []];
        $GLOBALS['cron'][] = ['hook' => 'mrg_weekly_sync_retry', 'time' => 2, 'recurrence' => false, 'args' => [2]];
        load_plugin($OLD_MAIN);
        load_plugin($NEW_MAIN);
        foreach ($GLOBALS['activation_hooks'] as $h) {
            if (plugin_basename_for($h['file']) === $NEW) {
                call_user_func($h['callback']);
            }
        }
        // Como hace activate_plugin(): relee la opción y añade la nueva.
        $current = get_option('active_plugins', []);
        $current[] = $NEW;
        sort($current);
        update_option('active_plugins', $current);
        break;

    case 'activate_old_while_new_active':
        // La 3.0 ya cargada; alguien activa la 2.x (WordPress incluye su archivo).
        update_option('active_plugins', [$NEW]);
        load_plugin($NEW_MAIN);
        load_plugin($OLD_MAIN);
        break;

    case 'activate_new_clean':
        update_option('active_plugins', []);
        load_plugin($NEW_MAIN);
        foreach ($GLOBALS['activation_hooks'] as $h) {
            call_user_func($h['callback']);
        }
        break;
}
