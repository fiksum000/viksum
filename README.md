# Billing RTRW Net

Aplikasi billing mandiri untuk layanan RT/RW Net, dibangun dengan Laravel 13, PHP 8.4, MariaDB, RouterOS API, Tripay, dan Fonnte. Target produksi adalah Ubuntu Server 22.04 di Proxmox LXC (CT). UI memakai latar belakang hitam di seluruh halaman.

## Fitur

- Role super admin, admin, operator, finance, dan technician dengan pembatasan route.
- Pelanggan PPPoE/Hotspot, paket, kredensial terenkripsi, portal pelanggan, serta import/export XLSX/CSV dengan pemetaan kolom Mikhmon.
- Invoice bulanan, pajak/diskon/denda, PDF, pencatatan tunai, link bayar, channel aktif Tripay, verifikasi callback, dan antrean aktivasi setelah pembayaran.
- MikroTik RouterOS: koneksi, PPPoE, hotspot voucher, isolir, unisolir, serta pengukuran delta counter FUP bulanan.
- Pengingat WhatsApp, template yang dapat diedit, pengiriman antrean dan broadcast.
- Inventaris OLT/ONU, pencatatan data optik, laporan keuangan, scheduler, worker, dan backup terenkripsi terjadwal.
- REST API baca-saja berversi untuk data pelanggan, invoice, dan status MikroTik dengan token API hash, kedaluwarsa, dan pencabutan.

## Spesifikasi server

- Ubuntu Server 22.04 LTS pada CT Proxmox.
- PHP 8.4-FPM dan Composer 2.
- MariaDB 10.6 atau lebih baru, Nginx, ekstensi PHP MySQL/Curl/XML/Mbstring/Zip/Bcmath/Intl/GD.
- Disarankan 2 vCPU dan 4 GB RAM untuk mulai; ukuran perlu disesuaikan dengan pola polling, queue, dan jumlah router.
- Alamat IP CT tetap, firewall sesuai kebutuhan, DNS/HTTPS untuk callback pembayaran dan akses luar jaringan.

> **Versi PHP:** paket ini Laravel 13 yang mensyaratkan PHP 8.3 atau lebih baru. Installer memilih PHP 8.4 karena PHP 8.2 pada rancangan awal tidak kompatibel. Ubuntu CT menjalankan aplikasi; Windows host Proxmox hanya dipakai untuk administrasi.

## Deploy ke CT Ubuntu Proxmox

Buat CT Ubuntu 22.04 dengan jaringan/IP yang sudah ditentukan, lalu masuk ke CT sebagai user sudo. Atur DNS dan akses HTTPS sebelum mengaktifkan webhook Tripay.

```bash
sudo apt update && sudo apt install -y git
sudo mkdir -p /var/www/billing-rtrwnet
sudo chown "$USER":"$USER" /var/www/billing-rtrwnet
git clone https://github.com/fiksum000/viksum.git /var/www/billing-rtrwnet
cd /var/www/billing-rtrwnet
bash scripts/install.sh
```

Installer meminta URL aplikasi, email admin, dan password awal, kemudian menyiapkan PHP 8.4, MariaDB, Nginx, tabel, worker, scheduler, serta backup harian. Tambahkan token Fonnte dan credential Tripay ke `/var/www/billing-rtrwnet/.env`, kemudian jalankan:

```bash
cd /var/www/billing-rtrwnet
sudo -u www-data php artisan config:cache
sudo systemctl restart billing-rtrwnet-worker billing-rtrwnet-scheduler
```

Lihat [DEPLOY_CHECKLIST.md](DEPLOY_CHECKLIST.md) untuk verifikasi produksi dan [GITHUB.md](GITHUB.md) untuk proses repository.

File Docker disediakan hanya untuk pengembangan lokal. Compose mewajibkan password database yang diisi sendiri dan hanya membuka port aplikasi di localhost. Untuk layanan produksi pada CT Proxmox, gunakan installer Ubuntu di atas agar aplikasi berjalan lewat Nginx, PHP-FPM, worker, scheduler, dan backup systemd.

## Integrasi

### Tripay

Set `TRIPAY_MODE`, `TRIPAY_API_KEY`, `TRIPAY_PRIVATE_KEY`, `TRIPAY_MERCHANT_CODE`, `TRIPAY_CALLBACK_URL`, dan `TRIPAY_RETURN_URL`. Callback berada di `/api/webhooks/tripay`. Form pembayaran mengambil channel aktif dari akun merchant. Aktifkan channel pada dashboard Tripay dan gunakan URL HTTPS yang dapat dicapai dari internet.

### MikroTik

Gunakan RouterOS 7.22, aktifkan API/API-SSL sesuai jaringan, dan buat akun layanan dengan hak minimum yang diperlukan. Jangan gunakan akun admin router umum atau masukkan rahasia ke Git.

### Fonnte

Atur `FONNTE_TOKEN`, pastikan nomor pelanggan sesuai format provider, dan pantau `billing-rtrwnet-worker`.

### OLT/ONU

Preset inventaris meliputi Hisfocus EPON dan C-Data EPON dengan manajemen Web. Pencatatan status/RX/TX masih manual. Form menyimpan host, port Web, username, dan password terenkripsi; polling/login browser belum diaktifkan karena endpoint dan autentikasi berbeda menurut model/firmware. Minta akses read-only serta uji adapter pada OLT yang dipakai sebelum polling dijadwalkan. Beberapa model C-Data mengiklankan Web, CLI, SNMP, Telnet, dan SSH; fitur persisnya perlu dicocokkan dengan model perangkat Anda ([contoh spesifikasi FD1304E](https://www.cdatatec.com/products/fd1304e-4-port-epon-olt/)). Lihat [docs/olt-epon.md](docs/olt-epon.md) untuk cara mencatat inventory dan batas polling.

### REST API

Lihat [docs/API.md](docs/API.md) untuk token, endpoint, filter, dan contoh pemanggilan. Token dapat dibuat dengan Artisan di server dan hanya ditampilkan satu kali.

## Backup

Backup terenkripsi berjalan harian menggunakan systemd timer dan disimpan di `storage/app/private/backups`. Simpan salinan terenkripsi di luar CT (NAS/PC lain) agar tetap tersedia jika disk atau CT rusak. Kunci `BACKUP_ENCRYPTION_KEY` harus disimpan aman di luar CT juga. Pemulihan database dan file storage lewat CLI tersedia; langkahnya ada di [docs/backup-restore.md](docs/backup-restore.md). `.env` aktif tidak ditimpa otomatis.

## Pengembangan lokal

```bash
cp .env.example .env
composer install
php artisan key:generate
# Atur database dan ADMIN_EMAIL serta ADMIN_PASSWORD kuat di .env
php artisan migrate --seed
php artisan serve
```

Workflow CI menjalankan pemeriksaan Composer dan suite Laravel di PHP 8.4 setelah commit didorong. OLT live polling dan restore backup dari panel admin belum termasuk. Jangan menaruh `.env`, kata sandi, token, credential router, data pelanggan, atau file dump di repository.
