@extends('layouts.app')
@section('content')
<h1>WhatsApp Fonnte</h1>
<section class="card card-body mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div><h2 class="h4 mb-1">Koneksi Gateway Fonnte</h2><p class="text-muted mb-0">Status: {{ $fonnteConfigured ? 'terhubung dan siap mengirim' : 'belum dikonfigurasi' }}</p></div>
    </div>
    <form method="POST" action="{{ route('whatsapp.connection.update') }}" class="row g-3 mt-1">
        @csrf @method('PUT')
        <div class="col-md-6"><label for="fonnte_account_name" class="form-label">Nama akun / identitas koneksi <span class="text-danger">*</span></label><input id="fonnte_account_name" name="fonnte_account_name" class="form-control" value="{{ old('fonnte_account_name', $settings?->fonnte_account_name) }}" minlength="2" maxlength="120" required placeholder="Contoh: Billing RT/RW Net"></div>
        <div class="col-md-6"><label for="fonnte_api_token" class="form-label">API Key / Token <span class="text-danger">*</span></label><input id="fonnte_api_token" name="fonnte_api_token" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $settings?->fonnte_api_token ? 'Token tersimpan; isi hanya untuk mengganti' : 'Masukkan token device Fonnte' }}"><div class="form-text">Token dienkripsi saat disimpan. Biarkan kosong untuk memakai token yang sudah tersimpan. Dapatkan token dari halaman device Fonnte.</div></div>
        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="fonnte_enabled" value="0"><input class="form-check-input" type="checkbox" role="switch" id="fonnte_enabled" name="fonnte_enabled" value="1" @checked(old('fonnte_enabled', $settings?->fonnte_enabled ?? false))><label class="form-check-label" for="fonnte_enabled">Aktifkan pengiriman WhatsApp</label></div></div>
        @if($webhookUrl)
            <div class="col-12"><label for="fonnte_webhook_url" class="form-label">Webhook aplikasi (masuk ke Fonnte → Device → Edit)</label><div class="input-group"><input id="fonnte_webhook_url" class="form-control" value="{{ $webhookUrl }}" readonly><button type="button" class="btn btn-outline-light" onclick="navigator.clipboard.writeText(document.getElementById('fonnte_webhook_url').value).then(()=>this.textContent='Tersalin')">Salin URL</button></div><div class="form-text">Aktifkan Auto Read di pengaturan perangkat Fonnte agar webhook menerima pesan masuk. URL ini mengandung token rahasia; jangan dibagikan ke publik.</div></div>
        @else
            <div class="col-12"><div class="alert alert-secondary mb-0">Simpan koneksi untuk membuat URL webhook rahasia.</div></div>
        @endif
        @if($settings?->fonnte_webhook_token)
            <div class="col-12"><div class="form-check"><input type="hidden" name="rotate_webhook_token" value="0"><input class="form-check-input" type="checkbox" id="rotate_webhook_token" name="rotate_webhook_token" value="1"><label class="form-check-label" for="rotate_webhook_token">Buat ulang URL/token webhook (URL lama langsung tidak berlaku)</label></div></div>
        @endif
        <div class="col-12 d-flex align-items-center flex-wrap gap-3"><button class="btn btn-primary">Simpan Koneksi</button><a class="link-info" href="https://docs.fonnte.com/webhook-url/" target="_blank" rel="noopener">Petunjuk resmi konfigurasi webhook Fonnte</a></div>
    </form>
</section>
<section class="card card-body mb-4">
    <h2 class="h5">Pesan masuk dari webhook</h2>
    <p class="text-muted small">Webhook hanya mencatat pesan dan status perangkat. Aplikasi belum membalas pesan masuk secara otomatis.</p>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Waktu</th><th>Pengirim / perangkat</th><th>Jenis</th><th>Pesan / status</th></tr></thead><tbody>
        @forelse($incomingLogs as $log)
            <tr><td>{{ $log->created_at->timezone(config('billing.timezone'))->format('d-m-Y H:i') }}</td><td>{{ $log->target }}</td><td>{{ $log->event === 'incoming' ? 'Pesan masuk' : 'Status perangkat' }}</td><td>{{ $log->message }}</td></tr>
        @empty
            <tr><td colspan="4">Belum ada data webhook yang diterima.</td></tr>
        @endforelse
    </tbody></table></div>
</section>
<div class="row g-3 mt-1">
    <div class="col-xl-4">
        <form method="POST" action="{{ route('whatsapp.broadcast') }}" class="card card-body">@csrf
            <h2 class="h5">Broadcast</h2>
            <label class="form-label">Penerima</label><select name="audience" class="form-select mb-3"><option value="all">Semua pelanggan bernomor WA</option><option value="active">Pelanggan aktif</option><option value="isolated">Pelanggan isolir</option><option value="unpaid">Pelanggan dengan invoice belum bayar</option></select>
            <label class="form-label">Pesan</label><textarea name="message" class="form-control" rows="6" maxlength="1000" required></textarea>
            <p class="text-muted small mt-2">Pesan dikirim melalui antrean. Pastikan target sudah benar sebelum menekan kirim.</p>
            <button class="btn btn-primary">Antrekan broadcast</button>
        </form>
    </div>
    <div class="col-xl-8">
        <h2 class="h5">Template pesan</h2>
        @foreach($templates as $template)
            <form method="POST" action="{{ route('whatsapp.templates.update',$template) }}" class="card card-body mb-3">@csrf @method('PUT')
                <div class="row g-2"><div class="col-md-4"><label class="form-label">Nama</label><input name="name" value="{{ $template->name }}" class="form-control" required><div class="text-muted small mt-1">Event: {{ $template->event }}</div></div><div class="col-md-8"><label class="form-label">Isi template</label><textarea name="body" class="form-control" rows="3" required>{{ $template->body }}</textarea><div class="text-muted small mt-1">Placeholder tersedia: &#123;&#123;name&#125;&#125;, &#123;&#123;invoice_number&#125;&#125;, &#123;&#123;amount&#125;&#125;, &#123;&#123;due_date&#125;&#125;, &#123;&#123;payment_url&#125;&#125;, &#123;&#123;message&#125;&#125;</div></div></div>
                <div class="d-flex justify-content-between align-items-center mt-3"><div class="form-check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" class="form-check-input" id="enabled-{{ $template->id }}" @checked($template->enabled)><label class="form-check-label" for="enabled-{{ $template->id }}">Aktif</label></div><button class="btn btn-outline-primary">Simpan template</button></div>
            </form>
        @endforeach
    </div>
</div>
@endsection
