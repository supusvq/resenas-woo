<?php
/**
 * Pruebas de la migración desde la 2.x (carpeta resenas_woo/) a la 3.0
 * (carpeta resenas-woo/): convivencia sin errores fatales, activación,
 * borrado seguro y desinstalación que conserva los datos.
 *
 * Usa como "versión antigua" el código real de la rama main (2.12.3), sacado
 * con git archive a una carpeta temporal.
 *
 * Uso: php tests/migration.php
 */

require __DIR__ . '/bootstrap.php';

define('MRG_VERSION', '3.0.0');
define('MRG_PATH', dirname(__DIR__) . '/');
define('MRG_BASENAME', 'resenas-woo/mis-resenas-de-google.php');
require MRG_PATH . 'includes/Migration.php';

use MRG\Migration;

$root = dirname(__DIR__);
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/mrg-migration-' . getmypid();
define('WP_PLUGIN_DIR', $tmp . '/plugins'); // Para delete_old_folder() en la sección 9.

function rrmdir($dir)
{
    if (Migration::is_link_like($dir)) {
        @unlink($dir) || @rmdir($dir);
        return;
    }
    if (!is_dir($dir)) {
        @chmod($dir, 0644);
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') {
            rrmdir($dir . '/' . $f);
        }
    }
    @rmdir($dir);
}

function rcopy($src, $dst)
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir($src . '/' . $f) ? rcopy($src . '/' . $f, $dst . '/' . $f) : copy($src . '/' . $f, $dst . '/' . $f);
    }
}

function setup_env($tmp, $root, $with_old = true)
{
    rrmdir($tmp);
    mkdir($tmp . '/wp/wp-admin/includes', 0777, true);
    mkdir($tmp . '/plugins/resenas-woo', 0777, true);
    file_put_contents($tmp . '/wp/wp-admin/includes/upgrade.php', "<?php\nfunction dbDelta(\$sql) { \$GLOBALS['dbdelta'] = (\$GLOBALS['dbdelta'] ?? 0) + 1; return []; }\n");
    file_put_contents($tmp . '/wp/wp-admin/includes/plugin.php', "<?php\n");
    // Copia de la 3.0 del árbol de trabajo, en su carpeta real resenas-woo/.
    foreach (['mis-resenas-de-google.php', 'uninstall.php'] as $f) {
        copy($root . '/' . $f, $tmp . '/plugins/resenas-woo/' . $f);
    }
    rcopy($root . '/includes', $tmp . '/plugins/resenas-woo/includes');

    if ($with_old) {
        // Código real de la 2.12.3 (rama main).
        $tar = $tmp . '/old.tar';
        $cmd = 'git -C ' . escapeshellarg($root) . ' archive --format=tar --prefix=resenas_woo/ main mis-resenas-de-google.php uninstall.php includes > ' . escapeshellarg($tar);
        exec($cmd, $out, $code);
        if ($code !== 0) {
            fwrite(STDERR, "No se pudo extraer la 2.x con git archive\n");
            exit(2);
        }
        (new PharData($tar))->extractTo($tmp . '/plugins');
        unlink($tar);
    }
}

function scenario($name, $tmp)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/load-plugins.php') . ' ' . escapeshellarg($name) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    $out = shell_exec($cmd);
    $json = json_decode((string) substr((string) $out, (int) strpos((string) $out, '{"fatal"')), true);
    if (!is_array($json)) {
        echo "  (salida cruda de $name: $out)\n";
        return ['fatal' => 'sin JSON', 'warnings' => ['?']];
    }
    return $json;
}

$OLD = Migration::OLD_BASENAME;
$NEW = Migration::NEW_BASENAME;

setup_env($tmp, $root);
$old_version = '';
if (preg_match('/Version:\s*([0-9.]+)/', (string) file_get_contents($tmp . '/plugins/resenas_woo/mis-resenas-de-google.php'), $m)) {
    $old_version = $m[1];
}
echo "\n(Versión antigua usada en las pruebas: $old_version)\n";

// ---------------------------------------------------------------------
echo "\n1. Guarda de carga\n";
$r = scenario('predefined', $tmp);
check('MRG_VERSION ya definida: sin error fatal', $r['fatal'] === null);
check('MRG_VERSION ya definida: sin avisos PHP', $r['warnings'] === []);
check('MRG_VERSION ya definida: no se redefine', $r['MRG_VERSION'] === 'otra-copia');
check('MRG_VERSION ya definida: no engancha plugins_loaded', $r['plugins_loaded'] === 0);
check('MRG_VERSION ya definida: aviso de admin registrado', $r['other_copy_notice'] === true);
check('MRG_VERSION ya definida: gancho de activación registrado', in_array([$NEW, 'MRG\\Migration::on_activate'], $r['activation_hooks'], true));

$r = scenario('clean', $tmp);
check('instalación limpia: carga la 3.0', $r['fatal'] === null && $r['MRG_VERSION'] === '3.0.0' && $r['plugins_loaded'] === 1);
check('instalación limpia: sin aviso de otra copia', $r['other_copy_notice'] === false);
check('instalación limpia: filtro de actualizaciones SupuHub', $r['update_filter'] === true);
check('instalación limpia: sin avisos PHP', $r['warnings'] === []);

$r = scenario('old_present_inactive', $tmp);
check('antigua presente pero inactiva: carga la 3.0', $r['MRG_VERSION'] === '3.0.0' && $r['plugins_loaded'] === 1 && !$r['other_copy_notice']);

// ---------------------------------------------------------------------
echo "\n2. Ambas activas (los dos órdenes de carga)\n";
$r = scenario('both_active_wp_order', $tmp);
check('sort() de WordPress pone resenas-woo antes que resenas_woo', ($r['load_order'] ?? []) === [$NEW, $OLD]);
check('orden WP: sin error fatal', $r['fatal'] === null);
check('orden WP: sin avisos PHP (sin constantes redefinidas)', $r['warnings'] === []);
check('orden WP: solo una copia engancha plugins_loaded', $r['plugins_loaded'] === 1);
check('orden WP: funciona la antigua y la nueva espera', $r['MRG_VERSION'] === $old_version && $r['other_copy_notice'] === true);

$r = scenario('both_active_old_first', $tmp);
check('antigua primero: sin error fatal', $r['fatal'] === null);
check('antigua primero: sin avisos PHP', $r['warnings'] === []);
check('antigua primero: solo una copia engancha plugins_loaded', $r['plugins_loaded'] === 1);
check('antigua primero: la nueva espera con aviso', $r['other_copy_notice'] === true);

// ---------------------------------------------------------------------
echo "\n3. Activación\n";
$r = scenario('activate_new_while_old_active', $tmp);
check('activar 3.0 con 2.x activa: sin error fatal', $r['fatal'] === null);
check('activar 3.0 con 2.x activa: sin avisos PHP', $r['warnings'] === []);
check('desactiva la antigua en silencio', $r['deactivated'] === [['plugin' => $OLD, 'silent' => true]]);
check('queda activa solo la nueva', $r['active_plugins'] === [$NEW]);
check('crea/actualiza tablas sin borrar nada', $r['dbdelta'] >= 1 && $r['drops'] === 0);
check('reprograma la importación semanal una vez y sin reintentos viejos', $r['cron'] === ['mrg_weekly_sync']);

$r = scenario('activate_new_clean', $tmp);
check('activación limpia: sin fatal ni avisos', $r['fatal'] === null && $r['warnings'] === []);
check('activación limpia: no desactiva nada', $r['deactivated'] === []);
check('activación limpia: tablas y cron', $r['dbdelta'] >= 1 && $r['cron'] === ['mrg_weekly_sync']);

$r = scenario('activate_old_while_new_active', $tmp);
check('activar la 2.x con la 3.0 cargada: sin error fatal', $r['fatal'] === null);
echo '    (avisos esperados del código antiguo, que no se puede cambiar: ' . count($r['warnings']) . ")\n";

// ---------------------------------------------------------------------
echo "\n4. Detección de la antigua\n";
$plugins = $tmp . '/plugins';
check('activa y presente', Migration::old_is_active([$NEW, $OLD], [], $plugins, $NEW));
check('activa en red', Migration::old_is_active([$NEW], [$OLD => 1], $plugins, $NEW));
check('inactiva', !Migration::old_is_active([$NEW], [], $plugins, $NEW));
check('listada pero sin archivo', !Migration::old_is_active([$OLD], [], $tmp . '/no-existe', $NEW));
check('esta copia es la carpeta antigua: no se detecta a sí misma', !Migration::old_is_active([$OLD], [], $plugins, $OLD));

// ---------------------------------------------------------------------
echo "\n5. Validación del borrado seguro\n";
$own = $plugins . '/resenas-woo';
$old_dir = $plugins . '/resenas_woo';
check('acepta exactamente la carpeta antigua', Migration::validate_old_dir($old_dir, $plugins, $own, false) === '');
check('acepta con barras de Windows', Migration::validate_old_dir(str_replace('/', '\\', $old_dir), $plugins, $own, false) === '');
check('rechaza si sigue activa', Migration::validate_old_dir($old_dir, $plugins, $own, true) !== '');
check('rechaza la carpeta nueva', Migration::validate_old_dir($own, $plugins, $own, false) !== '');
check('rechaza la carpeta de plugins', Migration::validate_old_dir($plugins, $plugins, $own, false) !== '');
check('rechaza otra carpeta', Migration::validate_old_dir($plugins . '/woocommerce', $plugins, $own, false) !== '');
check('rechaza subcarpeta', Migration::validate_old_dir($old_dir . '/includes', $plugins, $own, false) !== '');
check('rechaza path traversal', Migration::validate_old_dir($plugins . '/../plugins/resenas_woo', $plugins, $own, false) !== '');
check('rechaza traversal hacia fuera', Migration::validate_old_dir($old_dir . '/../../wp', $plugins, $own, false) !== '');
check('rechaza raíz del sistema', Migration::validate_old_dir('/', $plugins, $own, false) !== '');
check('rechaza ruta vacía', Migration::validate_old_dir('', $plugins, $own, false) !== '');
check('rechaza si WP_PLUGIN_DIR vacío', Migration::validate_old_dir('/resenas_woo', '', $own, false) !== '');
check('rechaza si esta copia vive en esa carpeta', Migration::validate_old_dir($old_dir, $plugins, $old_dir, false) !== '');
rename($old_dir . '/mis-resenas-de-google.php', $old_dir . '/otro.php');
check('rechaza si no contiene el plugin antiguo', Migration::validate_old_dir($old_dir, $plugins, $own, false) !== '');
rename($old_dir . '/otro.php', $old_dir . '/mis-resenas-de-google.php');
rrmdir($old_dir);
check('rechaza si ya no existe', Migration::validate_old_dir($old_dir, $plugins, $own, false) !== '');

// ---------------------------------------------------------------------
echo "\n6. Desinstalación de la 3.0\n";
function uninstall_run($tmp, array $options, $folder = 'resenas-woo')
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/uninstall.php') . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg(base64_encode(json_encode($options))) . ' ' . escapeshellarg($folder) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $json = json_decode(substr($out, (int) strpos($out, '{')), true);
    if (!is_array($json)) {
        echo "  (salida cruda: $out)\n";
        return ['queries' => ['?'], 'options' => []];
    }
    return $json;
}
$base = ['mrg_settings' => ['x' => 1], 'mrg_installation_id' => 'uuid-1', 'mrg_license' => ['key_enc' => 'x']];

setup_env($tmp, $root, false);
$u = uninstall_run($tmp, $base);
check('por defecto no borra tablas', count(array_filter($u['queries'], function ($q) { return stripos($q, 'DROP') !== false; })) === 0);
check('por defecto conserva ajustes y licencia', isset($u['options']['mrg_settings'], $u['options']['mrg_license']));
check('por defecto conserva installation_id', ($u['options']['mrg_installation_id'] ?? '') === 'uuid-1');
check('quita las tareas programadas', !in_array('mrg_weekly_sync', $u['cron'], true) && !in_array('mrg_license_check', $u['cron'], true));

$u = uninstall_run($tmp, $base + ['mrg_delete_data_on_uninstall' => 1]);
check('con la opción marcada borra tablas', count(array_filter($u['queries'], function ($q) { return stripos($q, 'DROP TABLE IF EXISTS wp_mrg_reviews') !== false; })) === 1);
check('con la opción marcada borra ajustes', !isset($u['options']['mrg_settings']) && !isset($u['options']['mrg_license']));
check('con la opción marcada conserva installation_id', ($u['options']['mrg_installation_id'] ?? '') === 'uuid-1');

setup_env($tmp, $root, true);
$u = uninstall_run($tmp, $base + ['mrg_delete_data_on_uninstall' => 1]);
check('con la versión antigua presente NO borra nada', count(array_filter($u['queries'], function ($q) { return stripos($q, 'DROP') !== false; })) === 0 && isset($u['options']['mrg_settings']));

// ---------------------------------------------------------------------
echo "\n7. Desinstalador antiguo neutralizado (regresión Codex #1)\n";
setup_env($tmp, $root, true);
$old_un = $plugins . '/resenas_woo/uninstall.php';
$base_old = ['mrg_settings' => ['x' => 1], 'mrg_version' => '2.12.3', 'mrg_installation_id' => 'uuid-1'];
$u = uninstall_run($tmp, $base_old, 'resenas_woo');
check('control: el uninstall.php ORIGINAL de la 2.x borra tablas y ajustes', count(array_filter($u['queries'], function ($q) { return stripos($q, 'DROP TABLE') !== false; })) === 2 && !isset($u['options']['mrg_settings']));

$r = scenario('activate_new_while_old_active', $tmp);
check('activar la 3.0 neutraliza el uninstall.php antiguo', $r['old_uninstall_neutralized'] === true && $r['fatal'] === null && $r['warnings'] === []);
$u = uninstall_run($tmp, $base_old, 'resenas_woo');
check('borrar la 2.x desde Plugins (3.0 inactiva) ya no borra tablas', count(array_filter($u['queries'], function ($q) { return stripos($q, 'DROP') !== false; })) === 0);
check('borrar la 2.x desde Plugins ya no borra ajustes', isset($u['options']['mrg_settings'], $u['options']['mrg_version']));

$stub = file_get_contents($old_un);
check('el stub usa return y no exit (no corta el borrado de archivos)', strpos($stub, 'return;') !== false && strpos($stub, 'exit') === false);
check('neutralizar dos veces no reescribe (idempotente)', Migration::neutralize_old_uninstall($plugins, $own) === '' && file_get_contents($old_un) === $stub);

setup_env($tmp, $root, true);
check('neutralizar desde la propia carpeta antigua no hace nada', Migration::neutralize_old_uninstall($plugins, $plugins . '/resenas_woo') === '' && strpos(file_get_contents($old_un), 'DROP TABLE') !== false);
check('sin WP_PLUGIN_DIR no hace nada', Migration::neutralize_old_uninstall('', $own) === '');
// Escritura a medias: tiene la marca pero no el contenido completo → se reescribe entero.
file_put_contents($old_un, substr($stub, 0, 60));
check('stub truncado con la marca: se detecta y se reescribe entero', Migration::neutralize_old_uninstall($plugins, $own) === '' && file_get_contents($old_un) === $stub);
check('no quedan temporales de la escritura atómica', glob(dirname($old_un) . '/.uninstall.php.mrg-*') === []);
check('2.x reinstalada encima: se vuelve a neutralizar', Migration::neutralize_old_uninstall($plugins, $own) === '' && strpos(file_get_contents($old_un), Migration::STUB_MARKER) !== false);

setup_env($tmp, $root, true);
chmod($old_un, 0444);
$err = Migration::neutralize_old_uninstall($plugins, $own);
check('sin permiso de escritura: devuelve el motivo', $err !== '' && strpos(file_get_contents($old_un), 'DROP TABLE') !== false);
chmod($old_un, 0644);

setup_env($tmp, $root, true);
unlink($old_un);
$outside = $tmp . '/fuera-uninstall.php';
file_put_contents($outside, "<?php // archivo ajeno\n");
if (@symlink($outside, $old_un)) {
    check('uninstall.php enlace simbólico: no se toca', Migration::neutralize_old_uninstall($plugins, $own) !== '' && file_get_contents($outside) === "<?php // archivo ajeno\n");
    unlink($old_un);
} else {
    echo "  SKIP uninstall.php enlace simbólico (este sistema no deja crear symlinks)\n";
}
check('sin uninstall.php antiguo: nada que hacer', Migration::neutralize_old_uninstall($plugins, $own) === '');

// La carpeta resenas_woo es una unión/enlace hacia otro sitio: no se escribe nada fuera.
setup_env($tmp, $root, true);
$elsewhere = $tmp . '/otra-carpeta';
rename($plugins . '/resenas_woo', $elsewhere);
$made = @symlink($elsewhere, $plugins . '/resenas_woo');
if (!$made && DIRECTORY_SEPARATOR === '\\') {
    exec('cmd /c mklink /J "' . str_replace('/', '\\', $plugins . '/resenas_woo') . '" "' . str_replace('/', '\\', $elsewhere) . '" >NUL 2>&1', $o, $code);
    $made = $code === 0 && is_dir($plugins . '/resenas_woo');
}
if ($made) {
    check('carpeta antigua enlazada fuera: se niega a escribir', Migration::neutralize_old_uninstall($plugins, $own) !== '' && strpos(file_get_contents($elsewhere . '/uninstall.php'), 'DROP TABLE') !== false);
    @unlink($plugins . '/resenas_woo') || @rmdir($plugins . '/resenas_woo');
} else {
    echo "  SKIP carpeta antigua enlazada (no se pudo crear enlace ni unión)\n";
}

// ---------------------------------------------------------------------
echo "\n8. Borrado recursivo sin seguir enlaces (regresión Codex #2)\n";
$victim = $tmp . '/fuera';

// a) Con un enlace real (symlink o unión de Windows) dentro de la carpeta antigua.
setup_env($tmp, $root, true);
@mkdir($victim, 0777, true);
file_put_contents($victim . '/importante.txt', 'no borrar');
$link = $plugins . '/resenas_woo/includes/enlace';
$made = @symlink($victim, $link);
if (!$made && DIRECTORY_SEPARATOR === '\\') {
    exec('cmd /c mklink /J "' . str_replace('/', '\\', $link) . '" "' . str_replace('/', '\\', $victim) . '" >NUL 2>&1', $o, $code);
    $made = $code === 0 && is_dir($link);
}
if ($made) {
    check('el enlace (o unión de Windows) se detecta como enlace', Migration::is_link_like($link));
    check('una carpeta normal no se toma por enlace', !Migration::is_link_like($plugins . '/resenas_woo/includes'));
    check('delete_tree borra la carpeta antigua', Migration::delete_tree($plugins . '/resenas_woo') && !file_exists($plugins . '/resenas_woo'));
    check('lo apuntado por el enlace sigue intacto', is_file($victim . '/importante.txt') && file_get_contents($victim . '/importante.txt') === 'no borrar');
} else {
    echo "  SKIP enlace real (no se pudo crear symlink ni unión)\n";
}

// b) Con detector simulado: una carpeta marcada como enlace no se recorre.
setup_env($tmp, $root, true);
$fake = $plugins . '/resenas_woo/falso-enlace';
mkdir($fake);
file_put_contents($fake . '/dentro.txt', 'x');
$seen = [];
$ok = Migration::delete_tree($plugins . '/resenas_woo', function ($p) use ($fake, &$seen) {
    $seen[] = str_replace('\\', '/', $p);
    return str_replace('\\', '/', $p) === $fake;
});
check('no entra en lo que el detector marca como enlace', is_file($fake . '/dentro.txt') && !in_array($fake . '/dentro.txt', $seen, true));
check('informa de que no pudo borrarlo todo', $ok === false);
check('borra el resto de la carpeta', !is_file($plugins . '/resenas_woo/mis-resenas-de-google.php'));

// ---------------------------------------------------------------------
echo "\n9. Permisos y multisitio en el borrado (regresión Codex #3 y #4)\n";
require MRG_PATH . 'includes/Admin/LicensePage.php';
$page = new \MRG\Admin\LicensePage();

function run_remove($page)
{
    $GLOBALS['transients'] = [];
    $_POST = ['confirm' => '1'];
    try {
        $page->handle_remove_old();
    } catch (TestRedirect $e) {
        return $GLOBALS['transients']['mrg_license_notice_1'] ?? null;
    } catch (TestDie $e) {
        return ['type' => 'die', 'message' => $e->getMessage()];
    }
    return null;
}

setup_env($tmp, $root, true);
$GLOBALS['options']['active_plugins'] = [$NEW];

$GLOBALS['caps'] = ['delete_plugins' => false];
$n = run_remove($page);
check('sin delete_plugins: no borra', is_dir($plugins . '/resenas_woo') && ($n['type'] ?? '') === 'error');
check('sin delete_plugins: lo explica', strpos($n['message'] ?? '', 'DISALLOW_FILE_MODS') !== false);
check('sin delete_plugins: removal_blocked_reason lo indica', Migration::removal_blocked_reason() !== '');
check('se comprueba el nonce', in_array('mrg_remove_old', $GLOBALS['nonce_checked'] ?? [], true));

$GLOBALS['caps'] = ['manage_options' => false];
$n = run_remove($page);
check('sin manage_options: wp_die', ($n['type'] ?? '') === 'die' && is_dir($plugins . '/resenas_woo'));

$GLOBALS['caps'] = [];
$GLOBALS['multisite'] = true;
$n = run_remove($page);
check('multisitio: borrado desactivado', is_dir($plugins . '/resenas_woo') && strpos($n['message'] ?? '', 'multisitio') !== false);
$GLOBALS['multisite'] = false;

$GLOBALS['options']['active_plugins'] = [$NEW, $OLD];
$n = run_remove($page);
check('antigua activa: no borra', is_dir($plugins . '/resenas_woo') && ($n['type'] ?? '') === 'error');

$GLOBALS['options']['active_plugins'] = [$NEW];
$n = run_remove($page);
check('con permisos, sin multisitio e inactiva: borra la carpeta', !file_exists($plugins . '/resenas_woo') && ($n['type'] ?? '') === 'success');
check('la carpeta nueva sigue', is_file($plugins . '/resenas-woo/mis-resenas-de-google.php'));

rrmdir($tmp);
summary('migration.php');
