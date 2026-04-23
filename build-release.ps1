param(
    [string]$OutputDir = ".\\dist"
)

$ErrorActionPreference = 'Stop'

$pluginRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginSlug = Split-Path -Leaf $pluginRoot
$pluginMainFile = Join-Path $pluginRoot 'mis-resenas-de-google.php'

if (-not (Test-Path $pluginMainFile)) {
    throw "No se encuentra el archivo principal del plugin."
}

$versionLine = Select-String -Path $pluginMainFile -Pattern 'Version:\s*([0-9.]+)' | Select-Object -First 1
if (-not $versionLine) {
    throw "No se ha podido detectar la version del plugin."
}

$version = $versionLine.Matches[0].Groups[1].Value
$stagingRoot = Join-Path $pluginRoot '.release-tmp'
$stagingDir = Join-Path $stagingRoot $pluginSlug
$distPath = Join-Path $pluginRoot $OutputDir
$zipPath = Join-Path $distPath ("{0}-{1}.zip" -f $pluginSlug, $version)

$excludeNames = @(
    '.release-tmp',
    'dist',
    'backend',
    'docs',
    'skills',
    'AGENTS.md',
    'CLAUDE.MD',
    'PLUGIN_STRUCTURE.md',
    'build-release.ps1'
)

if (Test-Path $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

if (-not (Test-Path $distPath)) {
    New-Item -ItemType Directory -Path $distPath | Out-Null
}

New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

Get-ChildItem -LiteralPath $pluginRoot -Force | Where-Object {
    $excludeNames -notcontains $_.Name
} | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination $stagingDir -Recurse -Force
}

if (Test-Path $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}

Compress-Archive -Path (Join-Path $stagingRoot '*') -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item -LiteralPath $stagingRoot -Recurse -Force

Write-Host "ZIP creado en: $zipPath"
