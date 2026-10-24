@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
  echo Brak PHP w PATH. Zainstaluj PHP 8.3 lub nowszy z PDO SQLite. Szczegoly: README.md.
  pause
  exit /b 1
)
php tools/check.php
if errorlevel 1 exit /b 1
php tools/prepare.php
if errorlevel 1 exit /b 1
echo Otworz http://127.0.0.1:8080/install. Zatrzymanie serwera: Ctrl+C.
php -S 127.0.0.1:8080 -t public public/router.php
