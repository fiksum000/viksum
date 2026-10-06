$ErrorActionPreference='Stop'
php -v
composer --version
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
composer install
php artisan key:generate
php artisan migrate --seed
Write-Host "Selesai. Jalankan: php artisan serve"
