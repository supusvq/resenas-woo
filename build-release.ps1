# Empaqueta dist/resenas-woo-<versión>.zip (ver build-release.php).
# Uso desde PowerShell: .\build-release.ps1
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
php build-release.php
if ($LASTEXITCODE -ne 0) { throw "El empaquetado ha fallado." }
