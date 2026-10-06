<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#000000"><title>Daftar Internet · Billing FIKSUM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root{color-scheme:dark}html,body{min-height:100%;background:#000!important;color:#f8f9fa}.card{--bs-card-bg:#101010;--bs-card-color:#f8f9fa;border-color:#2b2b2b}.form-control,.form-select{background:#111;color:#fff;border-color:#444}.form-control:focus,.form-select:focus{background:#151515;color:#fff}.text-muted,.form-text{color:#adb5bd!important}.honeypot{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden}
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark border-bottom border-secondary"><div class="container"><a class="navbar-brand" href="{{ route('portal.login') }}">Billing FIKSUM</a><a class="btn btn-sm btn-outline-light" href="{{ route('portal.login') }}">Portal pelanggan</a></div></nav>
<main class="container py-4 py-lg-5"><div class="row justify-content-center"><div class="col-lg-8 col-xl-7">
    <div class="mb-4"><h1 class="h2 mb-1">Pendaftaran Internet</h1><p class="text-muted mb-0">Isi data pemasangan. Permohonan langsung masuk ke Billing untuk diverifikasi tim FIKSUM.</p></div>
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($packages->isEmpty())<div class="alert alert-warning">Paket internet belum tersedia. Silakan hubungi admin FIKSUM.</div>@else
    <form method="POST" action="{{ route('register.store') }}" class="card"><div class="card-body p-3 p-md-4">@csrf
        <div class="honeypot" aria-hidden="true"><label for="website_url">Website</label><input id="website_url" name="website_url" tabindex="-1" autocomplete="off"></div>
        <h2 class="h5 mb-3">Data pemohon</h2>
        <div class="row g-3">
            <div class="col-md-6"><label for="name" class="form-label">Nama lengkap <span class="text-danger">*</span></label><input id="name" name="name" class="form-control" maxlength="120" value="{{ old('name') }}" autocomplete="name" required></div>
            <div class="col-md-6"><label for="whatsapp_number" class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label><input id="whatsapp_number" name="whatsapp_number" type="tel" inputmode="tel" class="form-control" placeholder="0812… atau 62812…" maxlength="30" value="{{ old('whatsapp_number') }}" autocomplete="tel" required><div class="form-text">Disimpan otomatis dengan awalan 62.</div></div>
            <div class="col-md-6"><label for="area" class="form-label">Area <span class="text-danger">*</span></label><input id="area" name="area" class="form-control" maxlength="120" value="{{ old('area') }}" placeholder="Contoh: M IKIN" required></div>
            <div class="col-md-6"><label for="package_id" class="form-label">Paket internet <span class="text-danger">*</span></label><select id="package_id" name="package_id" class="form-select" required><option value="">Pilih paket</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected((string)old('package_id') === (string)$package->id)>{{ $package->name }} — Rp {{ number_format($package->price,0,',','.') }}/bulan{{ $package->normal_speed ? ' · '.$package->normal_speed : '' }}</option>@endforeach</select></div>
            <div class="col-12"><label for="address" class="form-label">Alamat pemasangan <span class="text-danger">*</span></label><textarea id="address" name="address" rows="3" maxlength="2000" class="form-control" autocomplete="street-address" required>{{ old('address') }}</textarea></div>
            <div class="col-md-4"><label for="village" class="form-label">Desa / Kelurahan</label><input id="village" name="village" class="form-control" maxlength="120" value="{{ old('village') }}"></div>
            <div class="col-md-4"><label for="district" class="form-label">Kecamatan</label><input id="district" name="district" class="form-control" maxlength="120" value="{{ old('district') }}"></div>
            <div class="col-md-4"><label for="city" class="form-label">Kota / Kabupaten</label><input id="city" name="city" class="form-control" maxlength="120" value="{{ old('city') }}"></div>
            <div class="col-md-6"><label for="latitude" class="form-label">Latitude (opsional)</label><input id="latitude" name="latitude" type="number" step="any" class="form-control" value="{{ old('latitude') }}" placeholder="-0.9345797"></div>
            <div class="col-md-6"><label for="longitude" class="form-label">Longitude (opsional)</label><input id="longitude" name="longitude" type="number" step="any" class="form-control" value="{{ old('longitude') }}" placeholder="100.226458"></div>
            <div class="col-12"><div class="form-check"><input id="terms" name="terms" type="checkbox" value="1" class="form-check-input" @checked(old('terms')) required><label class="form-check-label" for="terms">Saya setuju data ini digunakan untuk memproses dan menghubungi saya tentang pemasangan.</label></div></div>
        </div>
        <div class="alert alert-info mt-4 mb-3">Setelah dikirim, Billing membuat akun pelanggan berstatus <strong>Uji coba / menunggu pemasangan</strong>. Koneksi internet belum aktif sampai tim memverifikasi area dan mengatur username PPP.</div>
        <button class="btn btn-primary w-100" type="submit">Kirim pendaftaran</button>
    </div></form>@endif
    <p class="text-muted small mt-3">Sudah menjadi pelanggan? <a class="link-light" href="{{ route('portal.login') }}">Masuk ke Portal Pelanggan</a>.</p>
</div></div></main>
</body>
</html>

