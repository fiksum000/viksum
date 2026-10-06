<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#000000">
    <title>Layanan Internet Terisolir | Billing FIKSUM</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; background: #000; color: #f5f5f5; }
        body { display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 520px); }
        .card { border: 1px solid #343434; border-radius: 20px; background: #101010; padding: clamp(24px, 6vw, 40px); box-shadow: 0 24px 80px #000; }
        .brand { color: #aaa; font-size: 14px; letter-spacing: .12em; text-transform: uppercase; }
        .icon { display: grid; place-items: center; width: 58px; height: 58px; margin: 26px 0 18px; border-radius: 16px; background: #361515; color: #ff7777; font-size: 30px; }
        h1 { margin: 0 0 12px; font-size: clamp(26px, 6vw, 34px); line-height: 1.15; }
        p { color: #c6c6c6; line-height: 1.65; }
        .note { margin: 24px 0; padding: 14px 16px; border-left: 3px solid #f0ad4e; border-radius: 8px; background: #211b11; color: #f2dfbd; font-size: 14px; }
        a.button { display: block; padding: 14px 18px; border-radius: 10px; background: #e53935; color: #fff; font-weight: 700; text-align: center; text-decoration: none; }
        a.button:hover { background: #c62828; }
        .foot { margin: 18px 0 0; color: #858585; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<main>
    <section class="card" aria-labelledby="title">
        <div class="brand">Billing FIKSUM</div>
        <div class="icon" aria-hidden="true">!</div>
        <h1 id="title">Layanan internet sedang terisolir</h1>
        <p>Akses internet pelanggan dibatasi sementara karena tagihan belum diselesaikan atau masih menunggu verifikasi pembayaran.</p>
        <div class="note">Buka portal pelanggan untuk melihat tagihan dan pilihan pembayaran. Setelah pembayaran terverifikasi, koneksi akan dipulihkan otomatis.</div>
        <a class="button" href="{{ route('portal.login') }}">Buka Portal Pelanggan</a>
        <p class="foot">Jika Anda sudah membayar, silakan tunggu verifikasi atau hubungi admin jaringan.</p>
    </section>
</main>
</body>
</html>
