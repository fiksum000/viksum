#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR=/var/www/billing-rtrwnet
if [[ "$(pwd -P)" != "$PROJECT_DIR" || ! -f artisan ]]; then
  echo "Run this installer from $PROJECT_DIR after cloning the repository there." >&2
  exit 1
fi
if [[ ! -r /etc/os-release ]]; then
  echo "Cannot determine the Linux distribution." >&2
  exit 1
fi
. /etc/os-release
if [[ "${ID:-}" != ubuntu || "${VERSION_ID:-}" != 22.04 ]]; then
  echo "This installer targets Ubuntu Server 22.04 LTS LXC." >&2
  exit 1
fi
sudo -v

read -rp "Public or LAN URL for Billing (example https://billing.example.com): " APP_URL
if [[ ! "$APP_URL" =~ ^https?://[^[:space:]]+$ ]]; then
  echo "APP_URL must start with http:// or https:// and contain no spaces." >&2
  exit 1
fi
read -rp "Initial Super Admin email: " ADMIN_EMAIL
if [[ ! "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
  echo "Enter a valid email address." >&2
  exit 1
fi
read -rsp "Initial Super Admin password (minimum 12 characters): " ADMIN_PASSWORD
echo
if (( ${#ADMIN_PASSWORD} < 12 )); then
  echo "The initial password must contain at least 12 characters." >&2
  exit 1
fi
read -rsp "Repeat initial password: " ADMIN_PASSWORD_CONFIRM
echo
if [[ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRM" ]]; then
  echo "Passwords do not match." >&2
  exit 1
fi
unset ADMIN_PASSWORD_CONFIRM

sudo apt-get update
sudo apt-get install -y software-properties-common ca-certificates lsb-release apt-transport-https curl git unzip mariadb-server nginx openssl
if ! apt-cache policy php8.4-cli | grep -q 'Candidate: [^()]'; then
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update
fi
sudo apt-get install -y php8.4-cli php8.4-fpm php8.4-mysql php8.4-curl php8.4-xml php8.4-mbstring php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd composer

if [[ ! -f .env ]]; then
  cp .env.example .env
fi
DB_PASSWORD=$(openssl rand -hex 32)
BACKUP_ENCRYPTION_KEY=$(openssl rand -hex 32)
export APP_ENV=production APP_DEBUG=false APP_URL APP_TIMEZONE=Asia/Jakarta
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=billing_rtrwnet DB_USERNAME=billing_app DB_PASSWORD
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database
export ADMIN_NAME="Super Admin" ADMIN_EMAIL ADMIN_PASSWORD BACKUP_ENCRYPTION_KEY
php scripts/configure-production-env.php
unset ADMIN_PASSWORD

sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS billing_rtrwnet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'billing_app'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'billing_app'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON billing_rtrwnet.* TO 'billing_app'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
unset DB_PASSWORD

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan key:generate --force
php artisan migrate --seed --force
php artisan storage:link || true
php artisan optimize

sudo install -o www-data -g www-data -d storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private/backups bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache

sudo tee /etc/nginx/sites-available/billing-rtrwnet >/dev/null <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/billing-rtrwnet/public;
    index index.php;
    client_max_body_size 15m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
    location ~* /(\.env|composer\.(json|lock)|artisan|database/) { deny all; }
}
NGINX
sudo ln -sfn /etc/nginx/sites-available/billing-rtrwnet /etc/nginx/sites-enabled/billing-rtrwnet
sudo nginx -t

sudo install -o root -g root -m 0644 systemd/billing-rtrwnet-worker.service /etc/systemd/system/billing-rtrwnet-worker.service
sudo install -o root -g root -m 0644 systemd/billing-rtrwnet-scheduler.service /etc/systemd/system/billing-rtrwnet-scheduler.service
sudo install -o root -g root -m 0644 systemd/billing-rtrwnet-backup.service /etc/systemd/system/billing-rtrwnet-backup.service
sudo install -o root -g root -m 0644 systemd/billing-rtrwnet-backup.timer /etc/systemd/system/billing-rtrwnet-backup.timer
sudo systemctl daemon-reload
sudo systemctl enable --now php8.4-fpm nginx mariadb billing-rtrwnet-worker billing-rtrwnet-scheduler billing-rtrwnet-backup.timer
sudo systemctl reload nginx

echo "Install selesai. URL sementara: $APP_URL"
echo "Atur FONNTE_TOKEN dan credential Tripay di $PROJECT_DIR/.env, lalu jalankan: php artisan config:cache"
echo "Setelah DNS/tunnel aktif, verifikasi callback URL publik dan aktifkan channel pembayaran di dashboard TriPay."
