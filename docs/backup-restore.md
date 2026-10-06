# Backup dan restore

## Cara kerja backup

Timer systemd membuat backup terenkripsi setiap hari di `storage/app/private/backups/YYYYMMDD-HHMMSS.zip.enc` dan HMAC integritasnya di file pasangannya `.zip.enc.mac`. Isinya dump MariaDB dan berkas `storage/app`; file backup, MAC, `.env`, token, dan dump SQL tidak boleh ditempatkan di GitHub. Simpan salinan kedua file backup **dan** `BACKUP_ENCRYPTION_KEY` di lokasi luar CT yang aksesnya dibatasi.

Periksa timer dan jalankan backup sebelum perubahan besar:

```bash
sudo systemctl status billing-rtrwnet-backup.timer
sudo -u www-data php artisan billing:backup
sudo ls -lh /var/www/billing-rtrwnet/storage/app/private/backups
```

## Restore database dan storage

Salin backup terenkripsi ke folder backup pada CT, pertahankan nama `YYYYMMDD-HHMMSS.zip.enc`, lalu pastikan `.env` CT berisi `BACKUP_ENCRYPTION_KEY` yang benar serta konfigurasi database tujuan. Prosedur ini membutuhkan utilitas `openssl`, `mariadb`, dan ekstensi PHP Zip.

```bash
cd /var/www/billing-rtrwnet
sudo install -o www-data -g www-data -m 0600 /lokasi/aman/20261006-120000.zip.enc storage/app/private/backups/20261006-120000.zip.enc
sudo install -o www-data -g www-data -m 0600 /lokasi/aman/20261006-120000.zip.enc.mac storage/app/private/backups/20261006-120000.zip.enc.mac
sudo -u www-data php artisan billing:restore 20261006-120000.zip.enc
```

Perintah meminta konfirmasi dan otomatis membuat backup pengaman database sebelum restore. Untuk eksekusi terotomasi, tambahkan `--force` hanya setelah target dan file backup diverifikasi. Proses memulihkan database dan menimpa file storage/app dengan file bernama sama. File storage lain yang hanya ada di CT akan tetap ada. File `.env` aktif tidak pernah diganti; setelah restore, cek `APP_KEY`, `BACKUP_ENCRYPTION_KEY`, URL, Tripay, Fonnte, dan credential router masih sesuai CT.

Sesudah restore:

```bash
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo systemctl restart billing-rtrwnet-worker billing-rtrwnet-scheduler php8.4-fpm
```

Pastikan halaman login, invoice, antrean job, integrasi pembayaran, dan layanan jaringan kembali normal. Simpan file lama dan backup pengaman sampai hasil pulih diverifikasi. Restore saat ini melalui CLI; panel web restore belum dibuat.
