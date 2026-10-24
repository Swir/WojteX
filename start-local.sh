#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
if ! command -v php >/dev/null 2>&1; then
  printf '%s\n' 'Brak PHP w PATH. Zainstaluj PHP 8.3 lub nowszy z PDO SQLite; szczegóły w README.md.'
  exit 1
fi
php tools/check.php
php tools/prepare.php
printf '%s\n' 'Otwórz http://127.0.0.1:8080/install. Zatrzymanie serwera: Ctrl+C.'
exec php -S 127.0.0.1:8080 -t public public/router.php
