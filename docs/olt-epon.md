# OLT EPON: Hisfocus dan C-Data

Untuk jaringan ini, inventaris disiapkan bagi OLT Hisfocus EPON dan C-Data EPON. Metode manajemen yang diketahui saat ini adalah **Web**. Nomor model dan firmware belum diketahui, jadi fitur aplikasi hanya menyimpan host/port, username/password Web, identitas perangkat, serta data ONU yang dicatat manual. Password OLT terenkripsi di database.

## Pengisian inventaris

1. Buka menu **OLT**, pilih vendor Hisfocus EPON atau C-Data EPON, isi nama dan model yang terbaca pada label/perangkat, alamat IP Web, port (umumnya 80/443; gunakan nilai aktual), username, serta catatan versi firmware.
2. Pilih `Web / HTTP` untuk mencatat jalur manajemen. Ini belum mengaktifkan login/polling otomatis.
3. Tambahkan ONU pada menu **ONU** dengan nomor PON/ONU dan serial number yang cocok di halaman OLT. Masukkan RX/TX dan status dari perangkat jika diperlukan.
4. Ulangi verifikasi setelah perubahan kabel, pindah port, atau penggantian ONU.

## Integrasi otomatis

Adapter live belum diaktifkan. Halaman login dan endpoint Web dapat berubah menurut model dan firmware; jangan memakai scraping yang bergantung pada tampilan HTML tanpa uji kompatibilitas. Setelah perangkat diketahui, implementasi perlu diuji hanya-baca terlebih dahulu untuk daftar ONU, status online/offline, optical RX/TX, serial/MAC, dan pemetaan slot/PON.

Batasi akses halaman Web OLT pada management VLAN atau IP CT. Jika vendor mendukung akun read-only, gunakan akun itu untuk polling. Jangan membuka Web OLT ke internet dan jangan menaruh password di tiket, screenshot, atau Git.

C-Data menyebut beberapa protokol manajemen termasuk Web pada spesifikasi model tertentu, contohnya FD1304E-B1; ini tidak menjamin fitur/protokol yang sama pada OLT C-Data milik Anda. Cocokkan dengan model dan firmware perangkat aktual: [spesifikasi resmi C-Data FD1304E](https://www.cdatatec.com/products/fd1304e-4-port-epon-olt/).
