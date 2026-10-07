<?php
/**
 * Shims mínimos de WordPress para las pruebas CLI (sin WordPress y sin red).
 * Solo desarrollo: no va en el ZIP del plugin.
 *
 * Antes de incluirlo, el script puede definir ABSPATH y WP_PLUGIN_DIR.
 */

if (php_sapi_name() !== 'cli') {
    die('Solo CLI');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'clave-de-prueba-auth');
    define('SECURE_AUTH_KEY', 'clave-de-prueba-secure');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
}

$GLOBALS['options'] = [];
$GLOBALS['site_transients'] = [];
$GLOBALS['next_response'] = null;
$GLOBALS['requests'] = [];
$GLOBALS['cron'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['activation_hooks'] = [];
$GLOBALS['deactivated'] = [];
$GLOBALS['plugin_basename'] = 'resenas-woo/mis-resenas-de-google.php';

function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function esc_url_raw($url) { return (string) $url; }
function esc_js($text) { return addslashes((string) $text); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key)); }
function wp_unslash($value) { return $value; }
function admin_url($path = '') { return 'https://cliente.es/wp-admin/' . $path; }
function home_url($path = '') { return 'https://cliente.es' . $path; }
function get_bloginfo($show = '') { return '6.8'; }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function wp_timezone() { return new DateTimeZone('Europe/Madrid'); }
function wp_json_encode($data) { return (string) json_encode($data); }
// Permisos del usuario de prueba: todo permitido salvo lo que diga $GLOBALS['caps'].
$GLOBALS['caps'] = [];
function current_user_can($cap) { return $GLOBALS['caps'][$cap] ?? true; }
function get_current_user_id() { return 1; }
function check_admin_referer($action) { $GLOBALS['nonce_checked'][] = $action; return 1; }

class TestRedirect extends Exception {}
class TestDie extends Exception {}
function wp_safe_redirect($url) { throw new TestRedirect((string) $url); }
function wp_die($message = '', $title = '', $args = []) { throw new TestDie((string) $message); }

$GLOBALS['transients'] = [];
function set_transient($name, $value, $ttl = 0) { $GLOBALS['transients'][$name] = $value; return true; }
function get_transient($name) { return $GLOBALS['transients'][$name] ?? false; }
function delete_transient($name) { unset($GLOBALS['transients'][$name]); return true; }

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['options'][$name] = $value;
    return true;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists($name, $GLOBALS['options'])) {
        return false;
    }
    $GLOBALS['options'][$name] = $value;
    return true;
}
function delete_option($name)
{
    unset($GLOBALS['options'][$name]);
    return true;
}
function get_site_option($name, $default = false) { return get_option('site:' . $name, $default); }
function delete_site_option($name) { return delete_option('site:' . $name); }
function delete_site_transient($name)
{
    unset($GLOBALS['site_transients'][$name]);
    return true;
}
$GLOBALS['multisite'] = false;
function is_multisite() { return $GLOBALS['multisite']; }

function wp_generate_uuid4()
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

/* Ganchos ------------------------------------------------------------ */

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['actions'][$hook][] = $callback;
    return true;
}
function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    return add_action($hook, $callback, $priority, $args);
}
function apply_filters($hook, $value)
{
    return $value;
}
function do_action($hook, ...$args)
{
    foreach ($GLOBALS['actions'][$hook] ?? [] as $cb) {
        call_user_func_array($cb, $args);
    }
}
function register_activation_hook($file, $callback)
{
    $GLOBALS['activation_hooks'][] = ['file' => $file, 'callback' => $callback];
}
function register_deactivation_hook($file, $callback) {}
function plugin_basename($file) { return $GLOBALS['plugin_basename']; }
function plugin_dir_path($file) { return rtrim(str_replace('\\', '/', dirname($file)), '/') . '/'; }
function plugin_dir_url($file) { return 'https://cliente.es/wp-content/plugins/' . basename(dirname($file)) . '/'; }

/* Cron --------------------------------------------------------------- */

function wp_next_scheduled($hook, $args = [])
{
    foreach ($GLOBALS['cron'] as $e) {
        if ($e['hook'] === $hook && $e['args'] === $args) {
            return $e['time'];
        }
    }
    return false;
}
function wp_schedule_event($time, $recurrence, $hook, $args = [])
{
    $GLOBALS['cron'][] = ['hook' => $hook, 'time' => $time, 'recurrence' => $recurrence, 'args' => $args];
    return true;
}
function wp_schedule_single_event($time, $hook, $args = [])
{
    return wp_schedule_event($time, false, $hook, $args);
}
function wp_clear_scheduled_hook($hook, $args = [])
{
    $GLOBALS['cron'] = array_values(array_filter($GLOBALS['cron'], function ($e) use ($hook, $args) {
        return !($e['hook'] === $hook && $e['args'] === $args);
    }));
}
function wp_unschedule_hook($hook)
{
    $GLOBALS['cron'] = array_values(array_filter($GLOBALS['cron'], function ($e) use ($hook) {
        return $e['hook'] !== $hook;
    }));
}

/* Plugins ------------------------------------------------------------ */

function is_plugin_active($plugin) { return in_array($plugin, (array) get_option('active_plugins', []), true); }
function is_plugin_active_for_network($plugin) { return false; }
function deactivate_plugins($plugins, $silent = false, $network_wide = null)
{
    foreach ((array) $plugins as $p) {
        $GLOBALS['deactivated'][] = ['plugin' => $p, 'silent' => $silent];
        $GLOBALS['options']['active_plugins'] = array_values(array_diff((array) get_option('active_plugins', []), [$p]));
    }
}

/* HTTP --------------------------------------------------------------- */

class TestError
{
    private $message;
    public function __construct($message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof TestError; }

function wp_remote_post($url, $args = [])
{
    $GLOBALS['requests'][] = ['url' => $url, 'args' => $args];
    $response = $GLOBALS['next_response'];
    return $response ?? ['status' => 200, 'body' => '{}'];
}
function wp_remote_retrieve_response_code($response) { return is_array($response) ? $response['status'] : 0; }
function wp_remote_retrieve_body($response) { return is_array($response) ? $response['body'] : ''; }

/* Utilidades de prueba ---------------------------------------------- */

$GLOBALS['failures'] = 0;
$GLOBALS['passes'] = 0;

function check($label, $condition)
{
    if ($condition) {
        $GLOBALS['passes']++;
        echo "  PASS $label\n";
        return;
    }
    $GLOBALS['failures']++;
    echo "  FAIL $label\n";
}

function throws($label, callable $fn)
{
    try {
        $fn();
        check($label, false);
    } catch (Throwable $e) {
        check($label . ' -> ' . $e->getMessage(), true);
    }
}

function respond(array $payload, $status = 200)
{
    $GLOBALS['next_response'] = ['status' => $status, 'body' => (string) json_encode($payload)];
}

function network_error($message = 'cURL error 28: timeout')
{
    $GLOBALS['next_response'] = new TestError($message);
}

function summary($name)
{
    echo "\n$name: {$GLOBALS['passes']} PASS, {$GLOBALS['failures']} FAIL\n";
    exit($GLOBALS['failures'] > 0 ? 1 : 0);
}
