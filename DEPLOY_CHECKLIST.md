# Checklist Deployment CT Proxmox

## CT Ubuntu

- [ ] Ubuntu Server 22.04 LTS; tetapkan IP dan DNS yang stabil.
- [ ] Ikuti [README.md](README.md): clone repo di `/var/www/billing-rtrwnet`, jalankan installer dengan sudo, set password awal yang kuat.
- [ ] Karena GitHub repository private, gunakan deploy key SSH khusus CT dengan akses baca-saja; jangan taruh private key atau PAT di repository.
- [ ] Laravel 13 memakai PHP 8.4 (PHP 8.2 tidak kompatibel), Composer 2, MariaDB, Nginx, PHP-FPM.
- [ ] Pastikan `APP_DEBUG=false`, `APP_URL` tepat, permission storage/cache benar, dan HTTPS aktif sebelum membuka ke internet.
- [ ] Periksa worker, scheduler, backup timer, log Laravel, dan `systemctl status`.
- [ ] Uji login admin, buat akun operator/finance/technician, review role dan akses minimal.
- [ ] Simpan cadangan terenkripsi harian di luar CT beserta `BACKUP_ENCRYPTION_KEY`; lakukan simulasi restore terencana.

## Jaringan dan MikroTik

- [ ] Batasi firewall; API 8728/8729 hanya dari IP CT billing atau management VLAN.
- [ ] Buat user API khusus dengan permission minimum.
- [ ] Buat profile `ISOLIR` dan profile normal/FUP pada router sebelum fitur isolir/FUP diaktifkan.
- [ ] Daftarkan router, uji koneksi, lalu cocokkan sample usage counter PPPoE sebelum menerapkan limit.
- [ ] Verifikasi pembayaran test berhasil memicu unisolir; uji reconnect dan status service.

## OLT Hisfocus EPON dan C-Data EPON

- [ ] Catat vendor, model persis, firmware, alamat/port web, jumlah PON port, serta metode login.
- [ ] Mulai dari data ONU manual; hanya catat status, RX/TX, serial, dan pemetaan pelanggan yang sudah diverifikasi.
- [ ] Jangan anggap penyimpanan alamat/credential Web sebagai telemetry. Polling adapter belum aktif.
- [ ] Aktifkan polling hanya sesudah endpoint/model login read-only diuji dan pemetaan PON/ONU/OID terbukti cocok.

## Pembayaran dan pesan

- [ ] Isi API key, private key, merchant code Tripay, return URL, dan HTTPS callback `/api/webhooks/tripay`.
- [ ] Aktifkan channel pembayaran yang sesuai di akun Tripay; halaman mengambil daftar channel aktif.
- [ ] Uji callback dengan nominal/reference yang sesuai dan pastikan callback tidak menduplikasi pembayaran.
- [ ] Isi token Fonnte, uji template satu pelanggan, lalu aktifkan reminder dan broadcast.
- [ ] Konfirmasi nomor admin dan pelanggan sebelum mengirim pesan produksi.

## API

- [ ] Buat token terpisah untuk setiap integrasi dengan masa berlaku terbatas.
- [ ] Berikan token hanya pada sistem server tepercaya; transport HTTPS; jangan masukkan ke frontend.
- [ ] Uji baca-saja, paginasi, throttle, masa berlaku, dan pencabutan token.

## Pemulihan

- [ ] Jalankan `php artisan billing:backup` dan salin file `.zip.enc` ke tempat aman di luar CT.
- [ ] Simpan kunci enkripsi secara terpisah dari backup.
- [ ] Ikuti [docs/backup-restore.md](docs/backup-restore.md) untuk simulasi restore via CLI; belum ada panel web restore.
