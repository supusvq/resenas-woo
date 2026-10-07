<?php
/**
 * Empaqueta el plugin para SupuHub: dist/resenas-woo-<versión>.zip con una
 * única carpeta raíz resenas-woo/ (igual al wp_slug del producto).
 *
 * Solo entra lo que necesita WordPress (lista blanca). Quedan fuera backend/,
 * docs/, tests/, dist/, skills/, diseno-supusnippets-pro/, .git*, los .md,
 * supuhub.json y los scripts de build. Rutas con "/" y permisos 0644/0755 sin
 * bit de ejecución en archivos (SupuHub rechaza ejecutables).
 *
 * Uso (PowerShell o Git Bash): php build-release.php   o   ./build-release.sh
 * Requiere la extensión zip de PHP.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

const SLUG = 'resenas-woo';
const MAIN = 'mis-resenas-de-google.php';

// Lo único que va en el ZIP.
const INCLUDE_PATHS = ['mis-resenas-de-google.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'templates', 'languages'];

$root = __DIR__;

function fail($msg)
{
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fail('Falta la extensión zip de PHP.');
}

$header = (string) file_get_contents($root . '/' . MAIN);
if (!preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/m', $header, $m)) {
    fail('No encuentro la cabecera Version: en ' . MAIN);
}
$version = $m[1];
if (!preg_match("/define\('MRG_VERSION',\s*'" . preg_quote($version, '/') . "'\)/", $header)) {
    fail("MRG_VERSION no coincide con la cabecera Version: $version");
}
if (strpos($header, 'Update URI: https://api.supudigital.es/products/resenaswoo') === false) {
    fail('Falta la cabecera Update URI de SupuHub.');
}

function excluded($rel)
{
    $base = basename($rel);
    if ('' === $base || '.' === $base[0]) {
        return true; // .git, .gitignore, .DS_Store...
    }
    if (preg_match('/\.md$/i', $base) || in_array(strtolower($base), ['thumbs.db', 'desktop.ini'], true)) {
        return true;
    }
    return false;
}

// Recoge archivos.
$files = [];
$dirs = [];
foreach (INCLUDE_PATHS as $path) {
    $abs = $root . '/' . $path;
    if (is_file($abs)) {
        $files[$path] = $abs;
        continue;
    }
    if (!is_dir($abs)) {
        continue;
    }
    $dirs[$path] = true;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $rel = $path . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($abs) + 1));
        if (excluded($rel) || $item->isLink()) {
            continue;
        }
        if ($item->isDir()) {
            $dirs[$rel] = true;
        } else {
            $files[$rel] = $item->getPathname();
        }
    }
}

// Secretos: ante la duda, no se empaqueta.
foreach ($files as $rel => $abs) {
    $content = (string) file_get_contents($abs);
    if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(sk|rk)_(live|test)_[A-Za-z0-9]{10,}|\bwhsec_[A-Za-z0-9]{10,}/', $content)) {
        fail("Posible secreto en $rel. Sácalo del plugin antes de empaquetar.");
    }
}

$dist = $root . '/dist';
if (!is_dir($dist)) {
    mkdir($dist, 0755, true);
}
$zip_path = $dist . '/' . SLUG . '-' . $version . '.zip';
if (is_file($zip_path)) {
    unlink($zip_path);
}

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fail("No puedo crear $zip_path");
}

$zip->addEmptyDir(SLUG);
$zip->setExternalAttributesName(SLUG . '/', ZipArchive::OPSYS_UNIX, (040755 << 16));
ksort($dirs);
foreach (array_keys($dirs) as $dir) {
    $name = SLUG . '/' . $dir . '/';
    $zip->addEmptyDir(rtrim($name, '/'));
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (040755 << 16));
}
ksort($files);
foreach ($files as $rel => $abs) {
    $name = SLUG . '/' . $rel;
    $zip->addFile($abs, $name);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100644 << 16));
}
$zip->close();

// Comprobación del resultado.
$zip = new ZipArchive();
$zip->open($zip_path, ZipArchive::CHECKCONS);
$roots = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (strpos($name, '\\') !== false) {
        fail("Ruta con barra invertida: $name");
    }
    $roots[explode('/', $name)[0]] = true;
}
$main = $zip->getFromName(SLUG . '/' . MAIN);
$zip->close();
if (array_keys($roots) !== [SLUG] || false === $main || strpos($main, 'Version: ' . $version) === false) {
    fail('El ZIP no tiene la estructura esperada.');
}

echo $zip_path . "\n";
echo count($files) . " archivos, versión $version, carpeta raíz " . SLUG . "/\n";
