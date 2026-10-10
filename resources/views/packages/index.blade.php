@extends('layouts.app')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Paket PPPoE</h1>
        <p class="text-secondary mb-0">Buat dan atur profil internet langsung dari billing. Paket akan mengirim kecepatan dan aturan FUP ke MikroTik secara otomatis.</p>
    </div>
    <span class="badge text-bg-dark">{{ $packages->count() }} paket</span>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('warning'))<div class="alert alert-warning" role="status">{{ session('warning') }}</div>@endif
@if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger">
        <strong>Periksa input berikut:</strong>
        <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card card-body h-100"><span class="text-secondary small">Paket dikelola billing</span><span class="display-6 fw-semibold">{{ $packages->where('sync_status', 'synced')->count() }}</span></div></div>
    <div class="col-6 col-xl-3"><div class="card card-body h-100"><span class="text-secondary small">Paket lama / manual</span><span class="display-6 fw-semibold">{{ $packages->where('sync_status', 'legacy')->count() }}</span></div></div>
    <div class="col-6 col-xl-3"><div class="card card-body h-100"><span class="text-secondary small">Butuh sinkronisasi</span><span class="display-6 fw-semibold">{{ $packages->whereIn('sync_status', ['failed', 'pending'])->count() }}</span></div></div>
    <div class="col-6 col-xl-3"><div class="card card-body h-100"><span class="text-secondary small">Total pelanggan</span><span class="display-6 fw-semibold">{{ number_format($packages->sum('customers_count')) }}</span></div></div>
</div>

<div class="card card-body mb-4">
    <div class="mb-3">
        <h2 class="h5 mb-1">Buat paket baru</h2>
        <p class="text-secondary small mb-0">Kamu tidak perlu membuat profil terlebih dahulu di Winbox. Billing membuat profil PPP normal dan profil FUP (bila diaktifkan) dengan nama internal tersendiri.</p>
    </div>
    <form method="POST" action="{{ route('packages.store') }}" class="row g-3">
        @csrf
        <div class="col-12 col-md-6 col-xl-4">
            <label class="form-label" for="new-name">Nama paket</label>
            <input id="new-name" name="name" class="form-control" value="{{ old('name') }}" placeholder="Contoh: Internet 20 Mbps" maxlength="100" required>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label" for="new-price">Harga bulanan (Rp)</label>
            <input id="new-price" name="price" type="number" min="0" class="form-control" value="{{ old('price') }}" required>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <label class="form-label" for="new-router">Router MikroTik</label>
            <select id="new-router" name="router_id" class="form-select" required>
                <option value="">Pilih router</option>
                @foreach($routers as $router)
                    <option value="{{ $router->id }}" @selected((string)old('router_id') === (string)$router->id)>{{ $router->name }} — {{ $router->host }}:{{ $router->port }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-1">
            <label class="form-label" for="new-upload">Upload</label>
            <input id="new-upload" name="upload_speed" class="form-control" value="{{ old('upload_speed', '2M') }}" placeholder="2M" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label class="form-label" for="new-download">Download</label>
            <input id="new-download" name="download_speed" class="form-control" value="{{ old('download_speed', '10M') }}" placeholder="10M" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
        </div>

        <div class="col-12"><hr class="my-1"><h3 class="h6 mb-0">Pengaturan burst (opsional)</h3></div>
        <div class="col-12 col-md-4">
            <div class="form-check mb-2">
                <input type="hidden" name="burst_enabled" value="0">
                <input class="form-check-input" id="new-burst-enabled" type="checkbox" name="burst_enabled" value="1" @checked(old('burst_enabled'))>
                <label class="form-check-label" for="new-burst-enabled">Aktifkan burst</label>
            </div>
            <div class="form-text">Burst memberi kecepatan sementara di atas batas normal bila threshold dan waktu burst terpenuhi.</div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="new-burst-limit">Batas burst upload/download</label>
            <input id="new-burst-limit" name="burst_limit" class="form-control" value="{{ old('burst_limit') }}" placeholder="5M/20M">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="new-burst-threshold">Threshold upload/download</label>
            <input id="new-burst-threshold" name="burst_threshold" class="form-control" value="{{ old('burst_threshold') }}" placeholder="2M/10M">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="new-burst-time">Waktu burst</label>
            <input id="new-burst-time" name="burst_time" class="form-control" value="{{ old('burst_time', '5s') }}" placeholder="5s" maxlength="8">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="new-priority">Prioritas</label>
            <select id="new-priority" name="priority" class="form-select" required>
                @for($p = 1; $p <= 8; $p++)<option value="{{ $p }}" @selected((string)old('priority', '8') === (string)$p)>{{ $p }}{{ $p === 1 ? ' (tertinggi)' : ($p === 8 ? ' (terendah)' : '') }}</option>@endfor
            </select>
        </div>

        <div class="col-12"><hr class="my-1"><h3 class="h6 mb-0">Pengaturan FUP</h3></div>
        <div class="col-12 col-md-3">
            <div class="form-check mb-2">
                <input type="hidden" name="fup_enabled" value="0">
                <input class="form-check-input" id="new-fup-enabled" type="checkbox" name="fup_enabled" value="1" @checked(old('fup_enabled'))>
                <label class="form-check-label" for="new-fup-enabled">Aktifkan FUP</label>
            </div>
            <div class="form-text">Jika kuota tercapai, billing mengganti profil PPP ke kecepatan FUP dan mencatat statusnya.</div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="new-fup-limit">Batas FUP (GB)</label>
            <input id="new-fup-limit" name="fup_limit_gb" type="number" min="0" step="0.1" class="form-control" value="{{ old('fup_limit_gb') }}" placeholder="Contoh 100">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="new-fup-upload">Upload setelah FUP</label>
            <input id="new-fup-upload" name="fup_upload_speed" class="form-control" value="{{ old('fup_upload_speed') }}" placeholder="512k">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="new-fup-download">Download setelah FUP</label>
            <input id="new-fup-download" name="fup_download_speed" class="form-control" value="{{ old('fup_download_speed') }}" placeholder="2M">
        </div>
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <p class="text-secondary small mb-0">Kecepatan diisi dalam format seperti 2M, 512k. Upload/download mengikuti sudut pandang pelanggan.</p>
            <button class="btn btn-primary px-4">Buat paket & sinkronkan ke MikroTik</button>
        </div>
    </form>
</div>

<div class="card card-body">
    <div class="mb-3">
        <h2 class="h5 mb-1">Paket billing</h2>
        <p class="text-secondary small mb-0">Profil dengan awalan VIKSUM-PPP dibuat dan dimiliki aplikasi. Profil MikroTik lama tidak diubah otomatis; paket lama perlu diatur dan disimpan ulang untuk migrasi yang aman.</p>
    </div>

    @forelse($packages as $package)
        <div class="border rounded-3 p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h3 class="h6 mb-0">{{ $package->name }}</h3>
                        @if($package->sync_status === 'synced' && !$package->sync_error)
                            <span class="badge text-bg-success">Tersinkron</span>
                        @elseif($package->sync_status === 'legacy')
                            <span class="badge text-bg-secondary">Profil lama / manual</span>
                        @elseif($package->sync_status === 'failed')
                            <span class="badge text-bg-danger">Gagal sinkron</span>
                        @else
                            <span class="badge text-bg-warning">{{ ucfirst($package->sync_status) }}</span>
                        @endif
                    </div>
                    <div class="text-secondary small mt-1">{{ $package->router?->name ?? 'Router belum ditautkan' }} · Rp {{ number_format($package->price, 0, ',', '.') }}/bulan · {{ $package->customers_count }} pelanggan</div>
                    <div class="mt-2">
                        <code>{{ $package->normal_profile }}</code>
                        @if($package->fup_enabled) <span class="text-secondary small">→ FUP:</span> <code>{{ $package->fup_speed_after }}</code> @endif
                    </div>
                    <div class="text-secondary small mt-1">
                        Normal {{ $package->upload_speed ?: '—' }} upload / {{ $package->download_speed ?: '—' }} download
                        @if($package->fup_enabled)
                            · FUP {{ $package->fup_limit_bytes ? number_format($package->fup_limit_bytes / 1073741824, 1).' GB' : 'belum ada kuota' }}
                            ({{ $package->fup_upload_speed ?: '—' }} up / {{ $package->fup_download_speed ?: '—' }} down)
                        @else
                            · FUP tidak aktif
                        @endif
                    </div>
                    @if($package->sync_error)<div class="small text-warning mt-2">{{ $package->sync_error }}</div>@endif
                    @if($package->legacy_normal_profile && $package->sync_status !== 'legacy')<div class="small text-secondary mt-1">Profil lama tersimpan sebagai arsip: <code>{{ $package->legacy_normal_profile }}</code></div>@endif
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($package->sync_status !== 'legacy')
                        <form method="POST" action="{{ route('packages.sync', $package) }}">@csrf<button class="btn btn-sm btn-outline-primary">Sinkron ulang</button></form>
                    @endif
                    <form method="POST" action="{{ route('packages.destroy', $package) }}" onsubmit="return confirm('Hapus paket ini? Penghapusan ditolak jika pelanggan atau secret/profil MikroTik masih menggunakannya.')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
                </div>
            </div>

            <details>
                <summary class="btn btn-sm btn-outline-light">Edit pengaturan paket</summary>
                <form method="POST" action="{{ route('packages.update', $package) }}" class="row g-3 mt-2">
                    @csrf @method('PUT')
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="name-{{ $package->id }}">Nama paket</label>
                        <input id="name-{{ $package->id }}" name="name" class="form-control" value="{{ $package->name }}" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="price-{{ $package->id }}">Harga bulanan (Rp)</label>
                        <input id="price-{{ $package->id }}" name="price" type="number" min="0" class="form-control" value="{{ $package->price }}" required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="router-{{ $package->id }}">Router MikroTik</label>
                        <select id="router-{{ $package->id }}" name="router_id" class="form-select" required>
                            @foreach($routers as $router)
                                <option value="{{ $router->id }}" @selected((int)$package->router_id === (int)$router->id)>{{ $router->name }} — {{ $router->host }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($package->sync_status === 'legacy')
                        <div class="col-12"><div class="alert alert-info small mb-0">Paket ini masih menunjuk profil lama <code>{{ $package->normal_profile }}</code>. Isi upload/download yang benar di bawah untuk memindahkannya ke profil milik billing. Profil lama akan disimpan dan tidak akan ditimpa.</div></div>
                    @endif
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="upload-{{ $package->id }}">Upload normal</label>
                        <input id="upload-{{ $package->id }}" name="upload_speed" class="form-control" value="{{ $package->upload_speed }}" placeholder="2M" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="download-{{ $package->id }}">Download normal</label>
                        <input id="download-{{ $package->id }}" name="download_speed" class="form-control" value="{{ $package->download_speed }}" placeholder="10M" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="form-check mb-2 mt-md-4">
                            <input type="hidden" name="burst_enabled" value="0">
                            <input class="form-check-input" id="burst-enabled-{{ $package->id }}" type="checkbox" name="burst_enabled" value="1" @checked($package->burst_enabled)>
                            <label class="form-check-label" for="burst-enabled-{{ $package->id }}">Aktifkan burst</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="burst-limit-{{ $package->id }}">Batas burst up/down</label>
                        <input id="burst-limit-{{ $package->id }}" name="burst_limit" class="form-control" value="{{ $package->burst_limit }}" placeholder="5M/20M">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="burst-threshold-{{ $package->id }}">Threshold up/down</label>
                        <input id="burst-threshold-{{ $package->id }}" name="burst_threshold" class="form-control" value="{{ $package->burst_threshold }}" placeholder="2M/10M">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="burst-time-{{ $package->id }}">Waktu burst</label>
                        <input id="burst-time-{{ $package->id }}" name="burst_time" class="form-control" value="{{ $package->burst_time ?: '5s' }}" maxlength="8" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="priority-{{ $package->id }}">Prioritas</label>
                        <select id="priority-{{ $package->id }}" name="priority" class="form-select" required>
                            @for($p = 1; $p <= 8; $p++)<option value="{{ $p }}" @selected((int)$package->priority === $p)>{{ $p }}{{ $p === 1 ? ' (tertinggi)' : ($p === 8 ? ' (terendah)' : '') }}</option>@endfor
                        </select>
                    </div>

                    <div class="col-12"><hr class="my-1"><h4 class="h6 mb-0">FUP</h4></div>
                    <div class="col-12 col-md-3">
                        <div class="form-check mb-2 mt-md-4">
                            <input type="hidden" name="fup_enabled" value="0">
                            <input class="form-check-input" id="fup-enabled-{{ $package->id }}" type="checkbox" name="fup_enabled" value="1" @checked($package->fup_enabled)>
                            <label class="form-check-label" for="fup-enabled-{{ $package->id }}">Aktifkan FUP</label>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="fup-limit-{{ $package->id }}">Batas FUP (GB)</label>
                        <input id="fup-limit-{{ $package->id }}" name="fup_limit_gb" type="number" min="0" step="0.1" class="form-control" value="{{ $package->fup_limit_bytes ? round($package->fup_limit_bytes / 1073741824, 2) : '' }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="fup-upload-{{ $package->id }}">Upload setelah FUP</label>
                        <input id="fup-upload-{{ $package->id }}" name="fup_upload_speed" class="form-control" value="{{ $package->fup_upload_speed }}" placeholder="512k">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="fup-download-{{ $package->id }}">Download setelah FUP</label>
                        <input id="fup-download-{{ $package->id }}" name="fup_download_speed" class="form-control" value="{{ $package->fup_download_speed }}" placeholder="2M">
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <p class="text-secondary small mb-0">Saat profil paket berubah, pelanggan aktif dapat diputus sebentar agar kecepatan baru diterapkan.</p>
                        <button class="btn btn-primary">Simpan & sinkronkan</button>
                    </div>
                </form>
            </details>
        </div>
    @empty
        <div class="text-center text-secondary p-4">
            <h3 class="h5">Belum ada paket</h3>
            <p class="mb-0">Buat paket pertama melalui form di atas.</p>
        </div>
    @endforelse
</div>
@endsection
