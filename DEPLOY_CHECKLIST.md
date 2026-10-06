# Checklist Deployment

## PC Windows

- PHP 8.4
- Composer 2.x
- MariaDB 10.6+
- `composer install`
- `.env`
- `php artisan key:generate`
- `php artisan migrate --seed`
- `php artisan serve`

## MikroTik

- API 8728 atau API-SSL 8729 aktif
- User API khusus billing
- Permission minimum yang diperlukan
- Profile `ISOLIR` dibuat sendiri di MikroTik
- Profile FUP setelah limit juga dibuat sendiri

## Tripay

- API Key
- Private Key
- Merchant Code
- Callback URL HTTPS
- Return URL HTTPS
- Test callback signature

## Fonnte

- Token
- Nomor target dalam format yang diterima provider
- Queue worker aktif

## Production

- Nginx root ke `/public`
- PHP-FPM 8.4
- MariaDB
- HTTPS
- Scheduler aktif
- Queue worker aktif
- Backup database harian
- APP_DEBUG=false
