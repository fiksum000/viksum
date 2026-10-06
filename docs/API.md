# REST API v1

API ini menyediakan akses **baca-saja** untuk integrasi dashboard/monitoring pihak lain. Semua URL diawali `/api/v1`. Gunakan HTTPS dan jangan menanam token di JavaScript/browser publik.

## Membuat dan mencabut token

Jalankan di root aplikasi CT sebagai user aplikasi. Hanya akun dengan role `admin` atau `super_admin` yang bisa menerbitkan token.

```bash
sudo -u www-data php artisan billing:api-token admin@example.com monitoring --days=365
```

Salin token yang keluar; token lengkap hanya ditampilkan saat pembuatan. Database hanya menyimpan SHA-256 token. Default berlaku 365 hari; set `--days=0` untuk token tanpa kedaluwarsa.

Cabut token dengan ID yang muncul saat dibuat:

```bash
sudo -u www-data php artisan billing:api-token --revoke=12
```

Token harus dikirim sebagai Bearer token. Batas API v1 adalah 60 permintaan per menit per sumber.

```bash
curl --fail-with-body -H 'Accept: application/json' \
  -H 'Authorization: Bearer 12|TOKEN_RAHASIA' \
  'https://billing.example.com/api/v1/customers?status=active&per_page=50'
```

## Endpoint

| Method | Path | Query/filter | Isi |
| --- | --- | --- | --- |
| GET | `/api/v1/customers` | `search`, `status`, `per_page` (1–100) | Pelanggan berhalaman, paket, router, data ONU yang tersedia |
| GET | `/api/v1/customers/{id}` | — | Rincian pelanggan yang aman untuk integrasi; tidak mengirim password, KTP, atau token portal |
| GET | `/api/v1/invoices` | `period=YYYY-MM`, `status`, `customer_code`, `per_page` | Daftar invoice dan total pembayaran |
| GET | `/api/v1/invoices/{id}` | — | Rincian invoice, item, dan riwayat transaksi |
| GET | `/api/v1/routers` | — | Router aktif yang dikenal beserta status koneksi terbaru |

Daftar berisi `data` dan metadata halaman (`current_page`, `last_page`, `per_page`, `total`). API hanya-baca: pembuatan pelanggan, pembayaran, dan perubahan router tetap lewat aplikasi web sampai kebutuhan integrasi write dan izin granular ditetapkan.

## Kode respons

- `200`: berhasil.
- `401`: token hilang, salah, dicabut, atau kedaluwarsa.
- `403`: role pemilik berubah/tidak berhak atau izin token tidak sesuai.
- `404`: record tidak ditemukan.
- `422`: filter/parameter tidak valid.
- `429`: batas permintaan terlampaui.

Berikan token hanya kepada sistem tepercaya. Jika token bocor, segera cabut dengan perintah Artisan dan terbitkan yang baru.
