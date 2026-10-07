<?php
/**
 * Pruebas de la integración con SupuHub: licencia, bloqueo de la importación,
 * reintentos del cron y actualizaciones. Sin WordPress y sin red (HTTP simulado).
 *
 * Uso: php tests/license.php
 */

require __DIR__ . '/bootstrap.php';

define('MRG_VERSION', '3.0.0');
define('MRG_PATH', dirname(__DIR__) . '/');
define('MRG_BASENAME', 'resenas-woo/mis-resenas-de-google.php');

require MRG_PATH . 'includes/Autoloader.php';
\MRG\Autoloader::register();

use MRG\License\License;
use MRG\License\Updater;
use MRG\Reviews\AutoSync;
use MRG\Reviews\ReviewSyncService;

const KEY = 'RESENAS-A1B2-C3D4-E5F6';

function valid_response(array $extra = [])
{
    respond(array_merge([
        'active' => true,
        'product' => 'resenaswoo',
        'status' => 'trial',
        'expires_at' => '2026-10-22 00:00:00',
        'environment' => 'production',
        'domain' => 'cliente.es',
        'sites_used' => 1,
        'sites_limit' => 1,
    ], $extra));
}

function age(array $fields)
{
    $state = $GLOBALS['options'][License::OPTION];
    foreach ($fields as $field => $hours) {
        $state[$field] = gmdate('Y-m-d H:i:s', time() - (int) ($hours * 3600));
    }
    $GLOBALS['options'][License::OPTION] = $state;
}

function cron_count($hook)
{
    return count(array_filter($GLOBALS['cron'], function ($e) use ($hook) {
        return $e['hook'] === $hook;
    }));
}

function near($ts, $expected, $tolerance = 5)
{
    return abs($ts - $expected) <= $tolerance;
}

// ---------------------------------------------------------------------
echo "\n1. Sin licencia\n";
check('no hay clave', !License::has_key());
check('no es válida', !License::is_valid());
check('estado none', License::status_code() === 'none');
$r = (new ReviewSyncService())->sync();
check('sync bloqueada sin licencia', !empty($r['license_required']) && isset($r['error']));
check('mensaje con enlace de compra', strpos($r['error'], License::BUY_URL) !== false);
check('sin licencia no sale ninguna petición', count($GLOBALS['requests']) === 0);

// ---------------------------------------------------------------------
echo "\n2. Activación rechazada\n";
throws('formato inválido', function () { License::activate('no-es-clave'); });
check('formato inválido no llama a SupuHub', count($GLOBALS['requests']) === 0);

respond(['active' => false, 'reason' => 'Licencia no válida.']);
throws('clave inválida', function () { License::activate(KEY); });
check('no se guardó clave', !License::has_key());

respond(['active' => false, 'reason' => 'Licencia caducada el 2026-01-01.', 'status' => 'expired']);
throws('clave caducada al activar', function () { License::activate(KEY); });
check('sigue sin clave', !License::has_key());

$GLOBALS['requests'] = [];
valid_response(['product' => 'supubot']);
throws('producto equivocado', function () { License::activate(KEY); });
check('producto equivocado no guarda clave', !License::has_key());
check('producto equivocado libera la plaza ocupada', count($GLOBALS['requests']) === 2 && strpos($GLOBALS['requests'][1]['url'], 'license/deactivate.php') !== false);

network_error();
throws('SupuHub inalcanzable al activar', function () { License::activate(KEY); });
check('sigue sin clave tras fallo de red', !License::has_key());

respond(['active' => false, 'error' => 'Formato de clave inválido.'], 400);
throws('HTTP 400', function () { License::activate(KEY); });

// ---------------------------------------------------------------------
echo "\n3. Activación correcta\n";
$GLOBALS['requests'] = [];
valid_response();
License::activate(KEY);
$req = $GLOBALS['requests'][0];
check('POST a license/activate.php', $req['url'] === 'https://api.supudigital.es/api/license/activate.php');
check('clave en cabecera X-License-Key', $req['args']['headers']['X-License-Key'] === KEY);
check('clave fuera de la URL', strpos($req['url'], KEY) === false);
$body = json_decode($req['args']['body'], true);
check('cuerpo sin clave', strpos($req['args']['body'], KEY) === false);
check('envía installation_id UUID', (bool) preg_match('/^[0-9a-f-]{36}$/', $body['installation_id']));
check('envía site_url y versión', $body['site_url'] === 'https://cliente.es/' && $body['plugin_version'] === '3.0.0');
check('hay clave', License::has_key());
check('es válida', License::is_valid());
check('estado trial', License::status_code() === 'trial');
check('etiqueta Prueba activa', License::status_label() === 'Prueba activa');
$state = License::state();
check('webs usadas/límite', $state['sites_used'] === 1 && $state['sites_limit'] === 1);
check('clave enmascarada', $state['key_masked'] === 'RESENAS-••••-••••-E5F6');
check('clave nunca en claro en las opciones', strpos(serialize($GLOBALS['options']), KEY) === false);
check('clave cifrada se descifra', \MRG\License\Crypto::decrypt($state['key_enc'], $state['key_iv'], $state['key_tag']) === KEY);
check('installation_id persistente', get_option(License::INSTALL_OPTION) === $body['installation_id']);
check('próxima comprobación a 12 h', near(strtotime($state['next_check_at'] . ' UTC'), time() + 12 * 3600));
check('cron de revalidación programado', cron_count(License::HOOK) === 1);

// ---------------------------------------------------------------------
echo "\n4. Importación con licencia\n";
$r = (new ReviewSyncService())->sync();
check('sync permitida con licencia (falla luego por falta de URL)', empty($r['license_required']) && strpos($r['error'], 'Google Maps') !== false);

// ---------------------------------------------------------------------
echo "\n5. Revalidación (12 h / 1 h)\n";
$GLOBALS['requests'] = [];
License::maybe_check();
check('no consulta antes de las 12 h', count($GLOBALS['requests']) === 0);

age(['next_check_at' => 0.01]);
valid_response(['status' => 'active', 'updates' => true]);
License::maybe_check();
check('consulta cuando toca', count($GLOBALS['requests']) === 1);
check('va a license/status.php', strpos($GLOBALS['requests'][0]['url'], 'license/status.php') !== false);
check('pasa a activa', License::status_code() === 'active');

age(['next_check_at' => 0.01]);
network_error();
License::maybe_check(); // no debe lanzar
$state = License::state();
check('fallo de red: reintento en 1 h', near(strtotime($state['next_check_at'] . ' UTC'), time() + 3600));
check('fallo de red: guarda el error', $state['last_error'] !== '');
check('fallo de red: sigue válida', License::is_valid());
check('fallo de red: aviso de inalcanzable', License::is_unreachable());

age(['last_valid_at' => 80]);
check('a las 80 h sin respuesta sigue en gracia', License::is_valid());
age(['last_valid_at' => 85]);
check('pasada la gracia (12+72 h) deja de valer', !License::is_valid());
check('pasada la gracia el estado es inválido', License::status_code() === 'invalid');
$r = (new ReviewSyncService())->sync();
check('pasada la gracia la sync se bloquea', !empty($r['license_required']));

valid_response(['status' => 'active']);
License::check();
check('vuelve a ser válida al responder SupuHub', License::is_valid());

// ---------------------------------------------------------------------
echo "\n6. Caducada / revocada / otro producto en revalidación\n";
respond(['active' => false, 'status' => 'expired', 'product' => 'resenaswoo', 'updates' => false, 'reason' => 'Licencia caducada.']);
License::check();
check('caducada se aplica de inmediato', !License::is_valid() && License::status_code() === 'expired');
$r = (new ReviewSyncService())->sync();
check('caducada bloquea la sync', !empty($r['license_required']));

respond(['active' => false, 'status' => 'revoked', 'product' => 'resenaswoo', 'reason' => 'Licencia revocada.']);
License::check();
check('revocada', License::status_code() === 'revoked' && !License::is_valid());

respond(['active' => true, 'status' => 'active', 'product' => 'otroproducto']);
License::check();
check('respuesta de otro producto no vale', !License::is_valid() && License::status_code() === 'other_product');

valid_response(['status' => 'active']);
License::check();
check('recuperada', License::is_valid());

// ---------------------------------------------------------------------
echo "\n7. Cron semanal y reintentos\n";
$GLOBALS['cron'] = [];
AutoSync::run(0);
check('con licencia y error recuperable programa reintento', cron_count(AutoSync::RETRY_HOOK) === 1);
$retry = array_values(array_filter($GLOBALS['cron'], function ($e) { return $e['hook'] === AutoSync::RETRY_HOOK; }))[0];
check('primer reintento a las 2 h con intento 1', near($retry['time'], time() + 2 * 3600) && $retry['args'] === [1]);
AutoSync::run(3);
check('tras el tercer reintento no programa más', cron_count(AutoSync::RETRY_HOOK) === 1);

$GLOBALS['cron'] = [];
$saved = $GLOBALS['options'][License::OPTION];
delete_option(License::OPTION);
$r = AutoSync::run(0);
check('sin licencia: resultado marcado', !empty($r['license_required']));
check('sin licencia: NO programa reintentos', cron_count(AutoSync::RETRY_HOOK) === 0);
check('sin licencia: guarda el último resultado', !empty(get_option('mrg_auto_sync_last')['result']['license_required']));
$GLOBALS['options'][License::OPTION] = $saved;

// ---------------------------------------------------------------------
echo "\n8. Actualizaciones\n";
$GLOBALS['requests'] = [];
respond([
    'update' => true, 'slug' => 'resenas-woo', 'plugin' => 'resenas-woo/mis-resenas-de-google.php',
    'version' => '3.1.0', 'new_version' => '3.1.0', 'package' => 'https://api.supudigital.es/api/update/download.php?t=abc',
    'tested' => '6.8', 'requires' => '6.0', 'requires_php' => '7.4', 'changelog' => 'Cambios', 'license_valid' => true,
]);
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('va a update/check.php', strpos($GLOBALS['requests'][0]['url'], 'update/check.php') !== false);
check('pasa la clave en cabecera', $GLOBALS['requests'][0]['args']['headers']['X-License-Key'] === KEY);
check('ofrece 3.1.0 con package', is_array($u) && $u['new_version'] === '3.1.0' && $u['package'] !== '');
check('slug resenas-woo', $u['slug'] === 'resenas-woo');
check('guarda caché', is_array(get_option(Updater::CACHE_OPTION)));

$other = Updater::check_update(false, [], 'otro/otro.php', []);
check('ignora otros plugins', $other === false);

respond(['update' => true, 'plugin' => 'supubot/supubot.php', 'version' => '9.0.0', 'package' => 'https://x/zip']);
delete_option(Updater::CACHE_OPTION);
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('rechaza ZIP de otro plugin', $u === false);

respond(['update' => true, 'plugin' => MRG_BASENAME, 'version' => '3.1.0', 'package' => '', 'license_valid' => false, 'reason' => 'Tu licencia ha caducado. Renuévala para descargar actualizaciones.']);
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('sin licencia válida: versión sin package', is_array($u) && $u['package'] === '');
check('sin licencia válida: explica el motivo', isset($u['upgrade_notice']) && strpos($u['upgrade_notice'], 'caducado') !== false);

respond(['update' => false, 'version' => '3.0.0']);
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('sin versión nueva: no hay package', is_array($u) && $u['package'] === '' && $u['new_version'] === '3.0.0');

update_option(Updater::CACHE_OPTION, ['time' => time(), 'data' => ['update' => true, 'plugin' => MRG_BASENAME, 'version' => '3.1.0', 'package' => 'https://x/cached']]);
network_error();
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('SupuHub caído: usa la última respuesta', is_array($u) && $u['package'] === 'https://x/cached');

$info = Updater::plugin_info(false, 'plugin_information', (object) ['slug' => 'resenas-woo']);
check('ficha Ver detalles', is_object($info) && $info->name === 'Reseñas Woo' && $info->version === '3.1.0');
check('ficha de otro slug intacta', Updater::plugin_info(false, 'plugin_information', (object) ['slug' => 'otro']) === false);

// ---------------------------------------------------------------------
echo "\n8b. Caché de actualizaciones: TTL y licencia no válida (regresión Codex #6)\n";
$cached_pkg = ['time' => time(), 'data' => ['update' => true, 'plugin' => MRG_BASENAME, 'version' => '3.1.0', 'package' => 'https://x/cached']];
update_option(Updater::CACHE_OPTION, $cached_pkg);
$saved = $GLOBALS['options'][License::OPTION];
$GLOBALS['options'][License::OPTION]['active'] = false; // licencia local no válida
network_error();
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('caché con licencia no válida: versión sin package', is_array($u) && $u['package'] === '' && $u['new_version'] === '3.1.0');
$info = Updater::plugin_info(false, 'plugin_information', (object) ['slug' => 'resenas-woo']);
check('ficha con licencia no válida: sin download_link', $info->download_link === '');
$GLOBALS['options'][License::OPTION] = $saved;

update_option(Updater::CACHE_OPTION, ['time' => time() - 13 * 3600] + $cached_pkg);
network_error();
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('caché de más de 12 h: no se usa', $u === false);
check('caché caducada: se borra', get_option(Updater::CACHE_OPTION) === false);

update_option(Updater::CACHE_OPTION, $cached_pkg);
respond(['update' => true, 'plugin' => MRG_BASENAME, 'version' => '3.1.0', 'package' => '', 'license_valid' => false, 'reason' => 'Tu licencia está suspendida.']);
Updater::check_update(false, [], MRG_BASENAME, []);
network_error();
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('una denegación de SupuHub sustituye al package guardado', is_array($u) && $u['package'] === '');

update_option(Updater::CACHE_OPTION, $cached_pkg);
respond(['active' => false, 'status' => 'suspended', 'product' => 'resenaswoo', 'reason' => 'Licencia suspendida.']);
License::check();
check('una negativa en la revalidación borra la caché', get_option(Updater::CACHE_OPTION) === false);
valid_response(['status' => 'active']);
License::check();
check('recuperada tras la negativa', License::is_valid());

echo "\n8c. Negativa explícita sin gracia (regresión Codex #5)\n";
respond(['active' => false, 'status' => 'active', 'product' => 'resenaswoo', 'reason' => 'Licencia no válida.']);
License::check();
check('active=false con status=active: deja de valer al momento', !License::is_valid());
check('active=false: borra la última validez', License::state()['last_valid_at'] === '');
$r = (new ReviewSyncService())->sync();
check('active=false: la sync se bloquea', !empty($r['license_required']));
age(['next_check_at' => 0.01]);
network_error();
License::maybe_check();
check('fallo de red tras la negativa: la gracia NO la recupera', !License::is_valid());
valid_response(['status' => 'active']);
License::check();
check('respuesta positiva: vuelve a valer', License::is_valid());

$GLOBALS['options'][License::OPTION]['active'] = false;
check('is_valid exige active === true en la última respuesta', !License::is_valid());
valid_response(['status' => 'active']);
License::check();

respond(['active' => true, 'status' => 'active', 'product' => 'otroproducto']);
License::check();
network_error();
try { License::check(); } catch (Throwable $e) {}
check('otro producto + fallo de red: tampoco hay gracia', !License::is_valid());
valid_response(['status' => 'active']);
License::check();

// ---------------------------------------------------------------------
echo "\n9. Desactivación\n";
$GLOBALS['requests'] = [];
respond(['deactivated' => true, 'sites_used' => 0]);
$install_id = get_option(License::INSTALL_OPTION);
License::deactivate();
check('avisa a license/deactivate.php', strpos($GLOBALS['requests'][0]['url'], 'license/deactivate.php') !== false);
check('borra la licencia local', !License::has_key() && !License::is_valid());
check('borra la caché de actualizaciones', get_option(Updater::CACHE_OPTION) === false);
check('quita el cron de revalidación', cron_count(License::HOOK) === 0);
check('conserva installation_id', get_option(License::INSTALL_OPTION) === $install_id);
$u = Updater::check_update(false, [], MRG_BASENAME, []);
check('sin clave el updater no ofrece nada', $u === false);

valid_response();
License::activate(KEY);
network_error();
throws('desactivar sin red avisa y no borra', function () { License::deactivate(); });
check('la licencia sigue guardada tras fallo', License::has_key());

summary('license.php');
