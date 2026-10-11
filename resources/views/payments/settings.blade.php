@extends('layouts.app')

@section('content')
<h1>Pengaturan Pembayaran</h1>
<p class="text-muted">Atur gateway Tripay dan QRIS DANA bisnis. Data kunci rahasia tidak pernah ditampilkan kembali setelah disimpan.</p>

@if($tripayCheck = session('tripay_check'))
    <div class="alert {{ $tripayCheck['ok'] ? ((int) ($tripayCheck['count'] ?? 0) > 0 ? 'alert-success' : 'alert-warning') : 'alert-danger' }}" role="status">
        {{ $tripayCheck['message'] }}
    </div>
@endif
<section class="card card-body mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h5 mb-1">Tes koneksi Tripay</h2>
            <p class="text-muted small mb-0">Simpan pengaturan di bawah terlebih dahulu. Tes ini membaca kanal pembayaran aktif dan tidak membuat transaksi.</p>
        </div>
        <form method="POST" action="{{ route('payment-settings.check-tripay') }}">
            @csrf
            <button class="btn btn-outline-info" type="submit">Cek koneksi Tripay</button>
        </form>
    </div>
</section>

<form method="POST" action="{{ route('payment-settings.update') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    <section class="card card-body mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div><h2 class="h4">Tripay</h2><p class="text-muted">Status: {{ $hasTripayCredentials ? 'kredensial tersedia' : 'kredensial belum lengkap' }}. Callback: <code>{{ url('/api/webhooks/tripay') }}</code></p></div>
            <div class="form-check form-switch"><input type="hidden" name="tripay_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" name="tripay_enabled" id="tripay_enabled" value="1" @checked(old('tripay_enabled', $settings?->tripay_enabled ?? false))><label class="form-check-label" for="tripay_enabled">Aktifkan Tripay</label></div>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="tripay_mode">Mode</label><select class="form-select" id="tripay_mode" name="tripay_mode"><option value="sandbox" @selected(old('tripay_mode', $settings?->tripay_mode ?? 'sandbox') === 'sandbox')>Sandbox</option><option value="production" @selected(old('tripay_mode', $settings?->tripay_mode ?? 'sandbox') === 'production')>Production</option></select></div>
            <div class="col-md-4"><label class="form-label" for="tripay_merchant_code">Merchant Code</label><input class="form-control" id="tripay_merchant_code" name="tripay_merchant_code" value="{{ old('tripay_merchant_code', $settings?->tripay_merchant_code) }}" autocomplete="off"></div>
            <div class="col-md-4"><label class="form-label" for="tripay_api_key">API Key</label><input class="form-control" type="password" id="tripay_api_key" name="tripay_api_key" autocomplete="new-password" placeholder="{{ $settings?->tripay_api_key ? 'Tersimpan; isi hanya jika ingin mengganti' : 'Masukkan API Key' }}"><div class="form-text">Kosongkan agar kunci yang tersimpan tetap digunakan.</div></div>
            <div class="col-md-6"><label class="form-label" for="tripay_private_key">Private Key</label><input class="form-control" type="password" id="tripay_private_key" name="tripay_private_key" autocomplete="new-password" placeholder="{{ $settings?->tripay_private_key ? 'Tersimpan; isi hanya jika ingin mengganti' : 'Masukkan Private Key' }}"><div class="form-text">Nilai dienkripsi sebelum disimpan.</div></div>
            <div class="col-md-6"><label class="form-label" for="tripay_callback_url">URL Callback</label><input class="form-control" type="url" id="tripay_callback_url" name="tripay_callback_url" value="{{ old('tripay_callback_url', $settings?->tripay_callback_url ?: route('tripay.webhook')) }}" placeholder="https://domain-anda/api/webhooks/tripay"><div class="form-text">Callback Tripay untuk status transaksi; harus dapat diakses dari internet.</div></div>
            <div class="col-md-6"><label class="form-label" for="tripay_return_url">URL Return (opsional)</label><input class="form-control" type="url" id="tripay_return_url" name="tripay_return_url" value="{{ old('tripay_return_url', $settings?->tripay_return_url) }}" placeholder="Kosongkan untuk kembali ke invoice"><div class="form-text">Jika kosong, pelanggan kembali ke halaman pembayaran invoice masing-masing.</div></div>
            <div class="col-12"><label class="form-label" for="tripay_whitelist_ips">Whitelist IP server</label><textarea class="form-control" id="tripay_whitelist_ips" name="tripay_whitelist_ips" rows="2" placeholder="IP publik server, satu IP per baris">{{ old('tripay_whitelist_ips', $settings?->tripay_whitelist_ips) }}</textarea><div class="form-text">Data untuk pengajuan/verifikasi Tripay; aplikasi menyimpannya sebagai catatan dan tidak mengubah firewall.</div></div>
        </div>
    </section>

    <section class="card card-body mb-3">
        <h2 class="h4">Data Vendor / Pengajuan Merchant Tripay</h2>
        <p class="text-muted">Data berikut hanya disimpan sebagai bahan pengajuan. Form ini tidak mengirim apa pun ke Tripay.</p>
        <h3 class="h6 mt-2">Informasi rekening (pendataan saja)</h3>
        <div class="row g-3">
            <div class="col-md-4"><label for="settlement_account_name" class="form-label">Nama rekening</label><input id="settlement_account_name" name="settlement_account_name" class="form-control" value="{{ old('settlement_account_name', $settings?->settlement_account_name) }}"></div>
            <div class="col-md-4"><label for="settlement_bank_name" class="form-label">Nama bank</label><input id="settlement_bank_name" name="settlement_bank_name" class="form-control" value="{{ old('settlement_bank_name', $settings?->settlement_bank_name) }}"></div>
            <div class="col-md-4"><label for="settlement_account_number" class="form-label">Nomor rekening</label><input id="settlement_account_number" name="settlement_account_number" class="form-control" value="{{ old('settlement_account_number') }}" autocomplete="off" placeholder="{{ $settings?->settlement_account_number ? 'Tersimpan; isi untuk mengganti' : 'Nomor rekening pencairan' }}"><div class="form-text">Nomor disimpan terenkripsi; kosongkan agar nilai lama tetap.</div></div>
        </div>
        <hr>
        <h3 class="h6">Form pengajuan vendor Tripay</h3>
        <div class="row g-3">
            <div class="col-md-6"><label for="merchant_name" class="form-label">Nama merchant</label><input id="merchant_name" name="merchant_name" class="form-control" value="{{ old('merchant_name', $settings?->merchant_name) }}"></div>
            <div class="col-md-6"><label for="merchant_website" class="form-label">URL website</label><input id="merchant_website" name="merchant_website" type="url" class="form-control" value="{{ old('merchant_website', $settings?->merchant_website) }}" placeholder="https://"></div>
            <div class="col-md-6"><label for="merchant_logo" class="form-label">Logo merchant (PNG/JPG/WEBP maks. 2 MB)</label><input id="merchant_logo" name="merchant_logo" type="file" accept="image/png,image/jpeg,image/webp" class="form-control">@if($merchantLogoUrl)<img src="{{ $merchantLogoUrl }}" alt="Logo merchant tersimpan" class="img-thumbnail mt-2" style="max-width:200px;max-height:140px">@endif</div>
            <div class="col-md-6"><label for="demo_isolation_date" class="form-label">Tanggal isolir akun demo</label><input id="demo_isolation_date" name="demo_isolation_date" type="date" class="form-control" value="{{ old('demo_isolation_date', $settings?->demo_isolation_date?->format('Y-m-d')) }}"><div class="form-text">Tanggal rencana untuk data pengajuan. Tidak membuat atau mengisolir pelanggan otomatis.</div></div>
            <div class="col-md-6"><label for="demo_username" class="form-label">Username login demo</label><input id="demo_username" name="demo_username" class="form-control" value="{{ old('demo_username', $settings?->demo_username) }}" autocomplete="off"></div>
            <div class="col-md-6"><label for="demo_password" class="form-label">Password login demo</label><input id="demo_password" name="demo_password" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $hasDemoPassword ? 'Tersimpan; isi untuk mengganti' : 'Masukkan password akun demo' }}"><div class="form-text">Disimpan terenkripsi. Jangan gunakan password Super Admin.</div></div>
            <div class="col-12"><label for="merchant_description" class="form-label">Deskripsi usaha / produk</label><textarea id="merchant_description" name="merchant_description" class="form-control" rows="4" maxlength="4000">{{ old('merchant_description', $settings?->merchant_description) }}</textarea></div>
        </div>
    </section>

    <section class="card card-body mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h2 class="h4">QRIS DANA Bisnis</h2><p class="text-muted">Pelanggan membayar dengan memindai QR. Konfirmasi pembayaran tetap dilakukan admin setelah memeriksa transaksi.</p></div><div class="form-check form-switch"><input type="hidden" name="dana_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" name="dana_enabled" id="dana_enabled" value="1" @checked(old('dana_enabled', $settings?->dana_enabled ?? false))><label class="form-check-label" for="dana_enabled">Tampilkan QR DANA</label></div></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="dana_account_name">Nama akun DANA Bisnis</label><input class="form-control" id="dana_account_name" name="dana_account_name" value="{{ old('dana_account_name', $settings?->dana_account_name) }}" maxlength="120"></div>
            <div class="col-md-6"><label class="form-label" for="dana_phone">Nomor DANA</label><input class="form-control" id="dana_phone" name="dana_phone" value="{{ old('dana_phone', $settings?->dana_phone) }}" maxlength="40" inputmode="tel"></div>
            <div class="col-md-6"><label class="form-label" for="dana_qr">Gambar QRIS (PNG/JPG/WEBP, maks. 5 MB)</label><input class="form-control" type="file" id="dana_qr" name="dana_qr" accept="image/png,image/jpeg,image/webp">@if($qrUrl)<div class="mt-3"><img src="{{ $qrUrl }}" alt="QRIS DANA bisnis tersimpan" style="max-width:220px;max-height:280px" class="img-thumbnail"><div class="small text-muted mt-1">QR saat ini akan dipertahankan jika tidak upload yang baru.</div></div>@endif</div>
        </div>
    </section>
    <button class="btn btn-primary">Simpan Pengaturan Pembayaran</button>
</form>
@endsection
