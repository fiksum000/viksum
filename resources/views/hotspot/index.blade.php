@extends('layouts.app')

@section('content')
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Hotspot Center</h1>
        <p class="text-secondary mb-0">Kelola paket, profil, voucher, masa berlaku, FUP, dan sinkronisasi dari billing.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge text-bg-dark border">{{ $stats['profiles'] }} profil</span>
        <span class="badge text-bg-dark border">{{ $stats['active'] }} voucher aktif</span>
    </div>
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
    @foreach([
        ['label' => 'Profil paket', 'value' => $stats['profiles'], 'icon' => 'bi-sliders'],
        ['label' => 'Total voucher', 'value' => $stats['vouchers'], 'icon' => 'bi-ticket-perforated'],
        ['label' => 'Voucher kedaluwarsa', 'value' => $stats['expired'], 'icon' => 'bi-clock-history'],
        ['label' => 'Perlu sinkronisasi', 'value' => $stats['sync_failed'], 'icon' => 'bi-arrow-repeat'],
    ] as $stat)
        <div class="col-6 col-xl-3">
            <div class="card card-body h-100">
                <div class="text-secondary small">{{ $stat['label'] }}</div>
                <div class="d-flex align-items-end justify-content-between gap-2">
                    <span class="display-6 fw-semibold">{{ number_format($stat['value']) }}</span>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if($canManageVouchers)
<div class="card card-body mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Buat profil Hotspot</h2>
            <p class="text-secondary small mb-0">Buat di billing dulu. Sistem membuat profil RouterOS dengan nama internal VIKSUM agar profil bawaan MikroTik tidak tertimpa.</p>
        </div>
        <span class="badge text-bg-primary">Billing sebagai pusat</span>
    </div>

    <form method="POST" action="{{ route('hotspot.profiles.store') }}" class="row g-3">
        @csrf
        <div class="col-12 col-md-6 col-xl-4">
            <label class="form-label" for="profile-router">Router MikroTik</label>
            <select id="profile-router" name="router_id" class="form-select" required>
                <option value="">Pilih router</option>
                @foreach($routers as $router)
                    <option value="{{ $router->id }}" @selected((string)old('router_id') === (string)$router->id)>{{ $router->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <label class="form-label" for="profile-name">Nama paket/profil</label>
            <input id="profile-name" name="name" class="form-control" maxlength="100" value="{{ old('name') }}" placeholder="Contoh: HEMAT-3JAM" required>
        </div>
        <div class="col-6 col-xl-2">
            <label class="form-label" for="profile-download">Download</label>
            <input id="profile-download" name="download_speed" class="form-control" value="{{ old('download_speed', '10M') }}" placeholder="10M" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
        </div>
        <div class="col-6 col-xl-2">
            <label class="form-label" for="profile-upload">Upload</label>
            <input id="profile-upload" name="upload_speed" class="form-control" value="{{ old('upload_speed', '2M') }}" placeholder="2M" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-shared">Maks. perangkat bersamaan</label>
            <input id="profile-shared" type="number" name="shared_users" min="1" max="200" class="form-control" value="{{ old('shared_users', 1) }}" required>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-fup-limit">FUP (GB, opsional)</label>
            <input id="profile-fup-limit" type="number" name="fup_limit_gb" min="0" step="0.1" class="form-control" value="{{ old('fup_limit_gb') }}" placeholder="Misal 20">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-fup-download">Download setelah FUP</label>
            <input id="profile-fup-download" name="fup_download_speed" class="form-control" value="{{ old('fup_download_speed') }}" placeholder="2M">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-fup-upload">Upload setelah FUP</label>
            <input id="profile-fup-upload" name="fup_upload_speed" class="form-control" value="{{ old('fup_upload_speed') }}" placeholder="512k">
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-validity">Masa berlaku (opsional)</label>
            <input id="profile-validity" type="number" name="validity_value" min="1" max="87600" class="form-control" value="{{ old('validity_value') }}" placeholder="Contoh: 3">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="profile-validity-unit">Satuan masa berlaku</label>
            <select id="profile-validity-unit" name="validity_unit" class="form-select">
                <option value="">Tanpa batas waktu</option>
                <option value="hour" @selected(old('validity_unit') === 'hour')>Jam</option>
                <option value="day" @selected(old('validity_unit') === 'day')>Hari</option>
                <option value="month" @selected(old('validity_unit') === 'month')>Bulan (30 hari)</option>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <div class="form-label">Pengaturan voucher</div>
            <div class="d-flex flex-wrap gap-3">
                <div class="form-check">
                    <input class="form-check-input" id="profile-first-login" type="checkbox" name="starts_on_first_login" value="1" @checked(old('starts_on_first_login', '1') === '1')>
                    <label class="form-check-label" for="profile-first-login">Masa aktif mulai saat login pertama</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" id="profile-bind-mac" type="checkbox" name="bind_mac" value="1" @checked(old('bind_mac'))>
                    <label class="form-check-label" for="profile-bind-mac">Ikat ke MAC perangkat pertama</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" id="profile-enabled" type="checkbox" name="enabled" value="1" @checked(old('enabled', '1') === '1')>
                    <label class="form-check-label" for="profile-enabled">Boleh dibuatkan voucher baru</label>
                </div>
            </div>
        </div>
        <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <p class="text-secondary small mb-0">Kecepatan memakai format RouterOS: 10M = 10 Mbps. Bulan dihitung 30 hari.</p>
            <button class="btn btn-primary px-4" type="submit">Buat profil & sinkronkan</button>
        </div>
    </form>
</div>

<div class="card card-body mb-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Profil yang dikelola billing</h2>
            <p class="text-secondary small mb-0">Edit pengaturan dari sini. Setelah disimpan, profil akan dikirim ulang ke router yang dipilih saat profil dibuat.</p>
        </div>
    </div>
    @forelse($profiles as $profile)
        <div class="border rounded-3 p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h3 class="h6 mb-0">{{ $profile->name }}</h3>
                        @if($profile->sync_status === 'synced')
                            <span class="badge text-bg-success">Tersinkron</span>
                        @elseif($profile->sync_status === 'failed')
                            <span class="badge text-bg-danger">Gagal sinkron</span>
                        @else
                            <span class="badge text-bg-warning">{{ ucfirst($profile->sync_status) }}</span>
                        @endif
                        @if(!$profile->enabled)<span class="badge text-bg-secondary">Tidak menerima voucher baru</span>@endif
                    </div>
                    <div class="text-secondary small mt-1">{{ $profile->router?->name ?? 'Router dihapus' }} · RouterOS: <code>{{ $profile->routerProfileName() }}</code></div>
                    @if($profile->last_synced_at)<div class="text-secondary small">Sinkron terakhir: {{ $profile->last_synced_at->format('d/m/Y H:i:s') }}</div>@endif
                    @if($profile->sync_error)<div class="text-danger small mt-1">{{ $profile->sync_error }}</div>@endif
                </div>
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('hotspot.profiles.sync', $profile) }}">@csrf<button class="btn btn-sm btn-outline-primary">Sinkron ulang</button></form>
                    <form method="POST" action="{{ route('hotspot.profiles.destroy', $profile) }}" onsubmit="return confirm('Hapus profil dari billing dan MikroTik? Profil yang masih dipakai akan ditolak.')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
                </div>
            </div>
            <form method="POST" action="{{ route('hotspot.profiles.update', $profile) }}" class="row g-2">
                @csrf @method('PUT')
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="down-{{ $profile->id }}">Download</label>
                    <input id="down-{{ $profile->id }}" class="form-control form-control-sm" name="download_speed" value="{{ $profile->download_speed }}" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="up-{{ $profile->id }}">Upload</label>
                    <input id="up-{{ $profile->id }}" class="form-control form-control-sm" name="upload_speed" value="{{ $profile->upload_speed }}" pattern="[0-9]+([.][0-9]+)?[kKmMgG]?" required>
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="shared-{{ $profile->id }}">Perangkat bersamaan</label>
                    <input id="shared-{{ $profile->id }}" type="number" min="1" max="200" class="form-control form-control-sm" name="shared_users" value="{{ $profile->shared_users }}" required>
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="fup-limit-{{ $profile->id }}">FUP GB</label>
                    <input id="fup-limit-{{ $profile->id }}" type="number" min="0" step="0.1" class="form-control form-control-sm" name="fup_limit_gb" value="{{ $profile->fup_limit_bytes ? round($profile->fup_limit_bytes / 1073741824, 2) : '' }}">
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="fup-down-{{ $profile->id }}">Download FUP</label>
                    <input id="fup-down-{{ $profile->id }}" class="form-control form-control-sm" name="fup_download_speed" value="{{ $profile->fup_download_speed }}">
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="fup-up-{{ $profile->id }}">Upload FUP</label>
                    <input id="fup-up-{{ $profile->id }}" class="form-control form-control-sm" name="fup_upload_speed" value="{{ $profile->fup_upload_speed }}">
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="validity-{{ $profile->id }}">Masa berlaku</label>
                    <input id="validity-{{ $profile->id }}" type="number" min="1" max="87600" class="form-control form-control-sm" name="validity_value" value="{{ $profile->validity_value }}" placeholder="Kosong = tanpa batas">
                </div>
                <div class="col-6 col-xl-2">
                    <label class="form-label small" for="validity-unit-{{ $profile->id }}">Satuan</label>
                    <select id="validity-unit-{{ $profile->id }}" name="validity_unit" class="form-select form-select-sm">
                        <option value="">Tanpa batas waktu</option>
                        <option value="hour" @selected($profile->validity_unit === 'hour')>Jam</option>
                        <option value="day" @selected($profile->validity_unit === 'day')>Hari</option>
                        <option value="month" @selected($profile->validity_unit === 'month')>Bulan (30 hari)</option>
                    </select>
                </div>
                <div class="col-12 col-xl-8 d-flex flex-wrap align-items-end gap-3">
                    <div class="form-check">
                        <input class="form-check-input" id="first-login-{{ $profile->id }}" type="checkbox" name="starts_on_first_login" value="1" @checked($profile->starts_on_first_login)>
                        <label class="form-check-label small" for="first-login-{{ $profile->id }}">Mulai saat login pertama</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" id="bind-mac-{{ $profile->id }}" type="checkbox" name="bind_mac" value="1" @checked($profile->bind_mac)>
                        <label class="form-check-label small" for="bind-mac-{{ $profile->id }}">Ikat MAC</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" id="enabled-{{ $profile->id }}" type="checkbox" name="enabled" value="1" @checked($profile->enabled)>
                        <label class="form-check-label small" for="enabled-{{ $profile->id }}">Bisa dipilih untuk voucher baru</label>
                    </div>
                </div>
                <div class="col-12 text-end">
                    <button class="btn btn-sm btn-primary">Simpan perubahan & sinkronkan</button>
                </div>
            </form>
            <div class="small text-secondary mt-2">
                {{ $profile->download_speed }} down / {{ $profile->upload_speed }} up ·
                {{ $profile->shared_users }} perangkat ·
                @if($profile->validity_value){{ $profile->validity_value }} {{ ['hour'=>'jam','day'=>'hari','month'=>'bulan (30 hari)'][$profile->validity_unit] ?? '' }}@if($profile->starts_on_first_login) sejak login pertama @else sejak voucher dibuat @endif
                @else tanpa batas waktu
                @endif
                @if($profile->fup_limit_bytes) · FUP {{ number_format($profile->fup_limit_bytes / 1073741824, 2) }} GB @endif
            </div>
        </div>
    @empty
        <div class="text-center text-secondary p-4">
            <div class="h5">Belum ada profil Hotspot</div>
            <p class="mb-0">Buat profil pertama di form atas. Profil akan dibuat di billing lalu disinkronkan ke router.</p>
        </div>
    @endforelse
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <form method="POST" action="{{ route('hotspot.generate') }}" class="card card-body h-100">
            @csrf
            <h2 class="h5">Buat voucher</h2>
            <p class="text-secondary small">Pilih profil yang sudah disinkronkan. Billing akan membuat kredensial lalu mengirimkannya ke MikroTik.</p>
            <label class="form-label" for="generator-profile">Paket/profil billing</label>
            <select id="generator-profile" name="profile_id" class="form-select mb-3" required>
                <option value="">Pilih paket</option>
                @foreach($generatorProfiles as $profile)
                    <option value="{{ $profile->id }}" @selected((string)old('profile_id') === (string)$profile->id)>
                        {{ $profile->name }} · {{ $profile->download_speed }}/{{ $profile->upload_speed }} · {{ $profile->router?->name }}
                    </option>
                @endforeach
            </select>
            @if($generatorProfiles->isEmpty())
                <div class="alert alert-info small">Belum ada profil siap pakai. Buat profil di atas dan pastikan status sinkronnya berhasil.</div>
            @endif
            <label class="form-label" for="voucher-quantity">Jumlah voucher (maksimum 200 sekali proses)</label>
            <input id="voucher-quantity" name="quantity" type="number" min="1" max="200" value="{{ old('quantity', 10) }}" class="form-control mb-3" required>
            <label class="form-label" for="voucher-prefix">Awalan username</label>
            <input id="voucher-prefix" name="prefix" value="{{ old('prefix', 'WIFI') }}" class="form-control" maxlength="12">
            <button class="btn btn-primary mt-3" @disabled($generatorProfiles->isEmpty())>Buat voucher</button>
        </form>
    </div>
</div>
@endif

<div class="card card-body mb-4">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Router dan user aktif</h2>
            <p class="text-secondary small mb-0">Sesi aktif dibaca langsung dari MikroTik RouterOS. Halaman ini hanya membaca sesi dan tidak mengubah konfigurasi router.</p>
        </div>
    </div>
    <div class="row g-2">
        @forelse($routers as $router)
            <div class="col-12 col-md-6 col-xl-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border rounded p-2 h-100">
                    <span>{{ $router->name }}</span>
                    <a class="btn btn-sm btn-outline-light" href="{{ route('hotspot.active', $router) }}" target="_blank" rel="noopener">Lihat user aktif</a>
                </div>
            </div>
        @empty
            <p class="text-secondary mb-0">Belum ada router yang diaktifkan.</p>
        @endforelse
    </div>
</div>

@if($canManageVouchers)
<form id="print-vouchers" method="GET" action="{{ route('hotspot.print') }}"></form>
@endif
<div class="card card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="h5 mb-1">Daftar voucher</h2>
            <p class="text-secondary small mb-0">Status, masa berlaku, pemakaian, dan status sinkronisasi router.</p>
        </div>
        @if($canManageVouchers)<button form="print-vouchers" class="btn btn-outline-primary btn-sm">Cetak voucher terpilih ke PDF</button>@endif
    </div>

    <form method="GET" action="{{ route('hotspot.index') }}" class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <input name="search" value="{{ $filters['search'] ?? '' }}" class="form-control form-control-sm" placeholder="Cari username atau catatan">
        </div>
        <div class="col-6 col-md-3">
            <select name="router_id" class="form-select form-select-sm">
                <option value="">Semua router</option>
                @foreach($routers as $router)<option value="{{ $router->id }}" @selected((string)($filters['router_id'] ?? '') === (string)$router->id)>{{ $router->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select name="profile_id" class="form-select form-select-sm">
                <option value="">Semua profil</option>
                @foreach($profiles as $profile)<option value="{{ $profile->id }}" @selected((string)($filters['profile_id'] ?? '') === (string)$profile->id)>{{ $profile->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">Semua status</option>
                @foreach(['pending'=>'Pending','active'=>'Aktif','disabled'=>'Nonaktif','expired'=>'Kedaluwarsa'] as $value=>$label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-sm btn-outline-light">Filter</button>
            <a href="{{ route('hotspot.index') }}" class="btn btn-sm btn-link">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr>
                @if($canManageVouchers)<th><span class="visually-hidden">Pilih</span></th>@endif
                <th>Voucher</th><th>Router / profil</th><th>Pemakaian</th><th>Masa berlaku</th><th>Status</th>
                @if($canManageVouchers)<th>Aksi</th>@endif
            </tr></thead>
            <tbody>
            @forelse($vouchers as $voucher)
                <tr>
                    @if($canManageVouchers)<td><input type="checkbox" name="ids[]" form="print-vouchers" value="{{ $voucher->id }}" aria-label="Pilih {{ $voucher->username }}"></td>@endif
                    <td>
                        <div class="fw-semibold">{{ $voucher->username }}</div>
                        @if($canManageVouchers)<code class="small">{{ $voucher->password }}</code>@endif
                        <div class="text-secondary small">{{ $voucher->comment }}</div>
                    </td>
                    <td>
                        <div>{{ $voucher->router?->name ?? 'Router dihapus' }}</div>
                        <div class="text-secondary small">{{ $voucher->hotspotProfile?->name ?? $voucher->profile }}</div>
                    </td>
                    <td>
                        <div>{{ number_format($voucher->total_bytes_used / 1073741824, 2) }} GB</div>
                        @if($voucher->hotspotProfile?->fup_limit_bytes)
                            <div class="text-secondary small">Batas {{ number_format($voucher->hotspotProfile->fup_limit_bytes / 1073741824, 2) }} GB {{ $voucher->fup_applied ? '· FUP aktif' : '' }}</div>
                        @endif
                    </td>
                    <td>
                        @if($voucher->expires_at)
                            <div>{{ $voucher->expires_at->format('d/m/Y H:i') }}</div>
                            @if($voucher->first_login_at)<div class="text-secondary small">Login pertama {{ $voucher->first_login_at->format('d/m/Y H:i') }}</div>@endif
                        @elseif($voucher->hotspotProfile?->validity_value)
                            <div>{{ $voucher->hotspotProfile->validity_value }} {{ ['hour'=>'jam','day'=>'hari','month'=>'bulan'][$voucher->hotspotProfile->validity_unit] ?? '' }}</div>
                            <div class="text-secondary small">{{ $voucher->hotspotProfile->starts_on_first_login ? 'Mulai saat login pertama' : 'Mulai sejak dibuat' }}</div>
                        @else
                            <span class="text-secondary">Tanpa batas</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $statusClass = match($voucher->status) {
                                'active' => 'success',
                                'expired' => 'secondary',
                                'disabled' => 'warning',
                                default => 'info',
                            };
                            $syncClass = $voucher->sync_status === 'synced' ? 'success' : (in_array($voucher->sync_status, ['failed','missing'], true) ? 'danger' : 'secondary');
                        @endphp
                        <span class="badge text-bg-{{ $statusClass }}">{{ $voucher->status }}</span>
                        <div class="small mt-1"><span class="badge text-bg-{{ $syncClass }}">{{ $voucher->sync_status ?? 'legacy' }}</span></div>
                        @if($voucher->sync_error)<div class="small text-danger mt-1">{{ $voucher->sync_error }}</div>@endif
                    </td>
                    @if($canManageVouchers)
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            @if($voucher->hotspot_profile_id)
                                <form method="POST" action="{{ route('hotspot.vouchers.sync', $voucher) }}">@csrf<button class="btn btn-sm btn-outline-info">Sinkron ulang</button></form>
                            @endif
                            @if($voucher->hotspotProfile && $voucher->hotspotProfile->enabled && $voucher->hotspotProfile->validity_value && $voucher->hotspotProfile->validity_unit && (!$voucher->hotspotProfile->starts_on_first_login || $voucher->first_login_at))
                                <form method="POST" action="{{ route('hotspot.vouchers.renew', $voucher) }}" onsubmit="return confirm('Perpanjang masa berlaku voucher ini? Voucher aktif yang belum kedaluwarsa akan diperpanjang dari tanggal kedaluwarsa saat ini. Pemakaian data dan FUP tidak direset.')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" type="submit">Perpanjang</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('hotspot.toggle', $voucher) }}">@csrf @method('PATCH')
                                <button class="btn btn-sm btn-outline-warning" @disabled($voucher->status === 'expired')>{{ $voucher->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            </form>
                            <form method="POST" action="{{ route('hotspot.destroy', $voucher) }}" onsubmit="return confirm('Putuskan sesi lalu hapus voucher dari MikroTik dan billing?')">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canManageVouchers ? 7 : 5 }}" class="text-center text-secondary p-4">Belum ada voucher untuk filter ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $vouchers->links() }}
</div>
@endsection
