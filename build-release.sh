#!/usr/bin/env bash
# Empaqueta dist/resenas-woo-<versión>.zip (ver build-release.php).
# Uso desde Git Bash: ./build-release.sh
set -euo pipefail
cd "$(dirname "$0")"
php build-release.php
