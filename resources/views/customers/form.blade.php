@extends('layouts.app')

@section('content')
@php
    $creating = !$customer->exists;
    $fupMode = old('fup_mode', $customer->fup_override === null ? ($customer->fup_enabled ? 'on' : 'inherit') : ($customer->fup_override ? 'on' : 'off'));
    $fupLimitGb = old('fup_limit_gb', $customer->fup_limit_bytes ? round($customer->fup_limit_bytes / 1073741824, 2) : '');
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="mb-1">{{ $creating ? 'Tambah Pelanggan' : 'Edit Pelanggan' }}</h1><p class="text-muted mb-0">Data pelanggan, layanan, jaringan, dan siklus tagihan.</p></div>
    <a href="{{ route('customers.index') }}" class="btn btn-outline-light">Kembali ke pelanggan</a>
</div>

<form method="POST" action="{{ $creating ? route('customers.store') : route('customers.update', $customer) }}" class="d-grid gap-3" data-creating="{{ $creating ? '1' : '0' }}">
    @csrf
    @if(!$creating) @method('PUT') @endif

    <section class="card card-body">
        <h2 class="h5">Detail pengguna billing</h2>
        <div class="row g-3">
            <div class="col-md-4"><label for="customer_code" class="form-label">Kode pelanggan</label><input id="customer_code" name="customer_code" class="form-control" maxlength="50" value="{{ old('customer_code', $customer->customer_code) }}" readonly><div class="form-text">Dibuat otomatis dan unik oleh sistem. Kode ini juga dipakai untuk login portal pelanggan.</div></div>
            <div class="col-md-4"><label for="name" class="form-label">Nama <span class="text-danger">*</span></label><input id="name" name="name" class="form-control" maxlength="120" value="{{ old('name', $customer->name) }}" required></div>
            <div class="col-md-4"><label for="area" class="form-label">Area <span class="text-danger">*</span></label><input id="area" name="area" class="form-control" maxlength="120" value="{{ old('area', $customer->area) }}" placeholder="Contoh: M IKIN" {{ $creating ? 'required' : '' }}><div class="form-text">Nama area bebas; data lama boleh tetap kosong.</div></div>
            <div class="col-md-4"><label for="whatsapp_number" class="form-label">WhatsApp</label><input id="whatsapp_number" name="whatsapp_number" type="tel" inputmode="tel" autocomplete="tel" class="form-control" maxlength="30" value="{{ old('whatsapp_number', $customer->whatsapp_number) }}" placeholder="08xxxxxxxxxx atau 628xxxxxxxxxx"><div class="form-text">Nomor otomatis disimpan ke format 62… . Satu nomor hanya boleh dipakai satu pelanggan.</div></div>
            <div class="col-md-4"><label for="registered_at" class="form-label">Tanggal daftar</label><input id="registered_at" name="registered_at" type="date" class="form-control" value="{{ old('registered_at', $customer->registered_at?->format('Y-m-d') ?? ($creating ? now()->format('Y-m-d') : '')) }}"></div>
            <div class="col-12"><label for="address" class="form-label">Alamat</label><textarea id="address" name="address" rows="2" class="form-control">{{ old('address', $customer->address) }}</textarea></div>
            <div class="col-12"><label for="notes" class="form-label">Catatan tambahan</label><textarea id="notes" name="notes" rows="2" maxlength="5000" class="form-control">{{ old('notes', $customer->notes) }}</textarea></div>
            <div class="col-md-2"><label class="form-label" for="rt">RT</label><input id="rt" name="rt" class="form-control" value="{{ old('rt', $customer->rt) }}"></div>
            <div class="col-md-2"><label class="form-label" for="rw">RW</label><input id="rw" name="rw" class="form-control" value="{{ old('rw', $customer->rw) }}"></div>
            <div class="col-md-3"><label class="form-label" for="village">Desa / Kelurahan</label><input id="village" name="village" class="form-control" value="{{ old('village', $customer->village) }}"></div>
            <div class="col-md-3"><label class="form-label" for="district">Kecamatan</label><input id="district" name="district" class="form-control" value="{{ old('district', $customer->district) }}"></div>
            <div class="col-md-2"><label class="form-label" for="city">Kabupaten / Kota</label><input id="city" name="city" class="form-control" value="{{ old('city', $customer->city) }}"></div>
            <div class="col-md-4"><label class="form-label" for="ktp_number">NIK</label><input id="ktp_number" name="ktp_number" class="form-control" maxlength="50" value="{{ old('ktp_number') }}" autocomplete="off"><div class="form-text">Disimpan terenkripsi. Kosongkan untuk mempertahankan data saat edit.</div></div>
        </div>
    </section>

    <section class="card card-body">
        <h2 class="h5">Lokasi pelanggan</h2>
        <p class="text-muted">Tekan tombol untuk meminta izin lokasi dari browser. Lokasi tidak diambil sebelum kamu menekan tombol.</p>
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="latitude">Latitude</label><input id="latitude" name="latitude" type="number" step="any" class="form-control" value="{{ old('latitude', $customer->latitude) }}" placeholder="-0.9345797"></div>
            <div class="col-md-4"><label class="form-label" for="longitude">Longitude</label><input id="longitude" name="longitude" type="number" step="any" class="form-control" value="{{ old('longitude', $customer->longitude) }}" placeholder="100.226458"></div>
            <div class="col-md-4 d-flex flex-wrap gap-2"><button class="btn btn-outline-light" type="button" id="use-location">Gunakan lokasi saya</button><a class="btn btn-outline-info d-none" id="google-maps-link" target="_blank" rel="noopener">Buka Google Maps</a></div>
            <div class="col-12"><div class="small" id="location-status" role="status"></div><div class="form-text">Alamat mengikuti data OpenStreetMap; detail RT/RW hanya terisi bila tersedia. © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.</div></div>
        </div>
    </section>

    <section class="card card-body">
        <h2 class="h5">Layanan dan data login</h2>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="service_type">Jenis layanan <span class="text-danger">*</span></label><select id="service_type" name="service_type" class="form-select" required><option value="pppoe" @selected(old('service_type', $customer->service_type ?: 'pppoe') === 'pppoe')>PPPoE</option><option value="hotspot" @selected(old('service_type', $customer->service_type) === 'hotspot')>Hotspot</option></select></div>
            <div class="col-md-4"><label class="form-label" for="router_id">Router MikroTik</label><select id="router_id" name="router_id" class="form-select"><option value="">Pilih router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected((string)old('router_id', $customer->router_id) === (string)$router->id)>{{ $router->name }} — {{ $router->host }}:{{ $router->port }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label" for="package_id">Paket billing / profil PPP <span class="text-danger">*</span></label><select id="package_id" name="package_id" class="form-select" required><option value="">Pilih router lebih dulu</option>@foreach($packages as $p)<option value="{{ $p->id }}" data-router-id="{{ $p->router_id }}" data-profile="{{ $p->normal_profile }}" data-fup-enabled="{{ $p->fup_enabled ? '1' : '0' }}" data-fup-limit="{{ $p->fup_limit_bytes ?? '' }}" data-fup-speed="{{ $p->fup_speed_after ?? '' }}" @selected((string)old('package_id', $customer->package_id) === (string)$p->id)>{{ $p->name }} — Rp {{ number_format($p->price, 0, ',', '.') }} | {{ $p->router?->name ?? 'Router belum ditautkan' }} / {{ $p->normal_profile }}</option>@endforeach</select><div class="form-text">Paket menentukan tagihan dan profil PPP yang sama-sama dipakai pelanggan.</div><div class="form-text text-warning" id="package-mapping-note" role="status"></div></div>
            <div class="col-md-4 pppoe-field"><label class="form-label" for="pppoe_username">Username PPP <span class="text-danger">*</span></label><input id="pppoe_username" name="pppoe_username" class="form-control" value="{{ old('pppoe_username', $customer->pppoe_username) }}" maxlength="120"></div>
            <div class="col-md-4 pppoe-field"><label class="form-label" for="pppoe_password">Password PPP <span class="text-danger">{{ $creating ? '*' : '' }}</span></label><input id="pppoe_password" name="pppoe_password" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $creating ? '' : 'Kosongkan jika tidak diubah' }}"></div>
            <div class="col-md-4 hotspot-field"><label class="form-label" for="hotspot_username">Username Hotspot <span class="text-danger">*</span></label><input id="hotspot_username" name="hotspot_username" class="form-control" value="{{ old('hotspot_username', $customer->hotspot_username) }}" maxlength="120"></div>
            <div class="col-md-4 hotspot-field"><label class="form-label" for="hotspot_password">Password Hotspot <span class="text-danger">{{ $creating ? '*' : '' }}</span></label><input id="hotspot_password" name="hotspot_password" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $creating ? '' : 'Kosongkan jika tidak diubah' }}"></div>
            <div class="col-md-4"><label class="form-label" for="pppoe_ip">IP statis PPP</label><input id="pppoe_ip" name="pppoe_ip" class="form-control" value="{{ old('pppoe_ip', $customer->pppoe_ip) }}" placeholder="Opsional"></div>
            <div class="col-md-4"><label class="form-label" for="pppoe_mac">MAC address terkunci</label><input id="pppoe_mac" name="pppoe_mac" class="form-control" value="{{ old('pppoe_mac', $customer->pppoe_mac) }}" placeholder="AA:BB:CC:DD:EE:FF"></div>
            <div class="col-md-4"><label class="form-label" for="pppoe_profile_display">Profil PPP dari paket</label><input id="pppoe_profile_display" class="form-control" value="{{ old('pppoe_profile_normal', $customer->package?->normal_profile ?: $customer->pppoe_profile_normal) }}" readonly><input type="hidden" id="pppoe_profile_normal" name="pppoe_profile_normal" value="{{ old('pppoe_profile_normal', $customer->package?->normal_profile ?: $customer->pppoe_profile_normal) }}"><div class="form-text">Profil ini mengikuti paket; ubah pemetaannya di menu Paket &amp; Profil PPP.</div></div>
            <div class="col-md-4"><label class="form-label" for="pppoe_profile_isolir">Profil isolir PPP</label><input id="pppoe_profile_isolir" name="pppoe_profile_isolir" class="form-control" value="{{ old('pppoe_profile_isolir', $customer->pppoe_profile_isolir ?: 'ISOLIR') }}"></div>
            <div class="col-md-4"><label class="form-label" for="portal_password">Password portal pelanggan</label><input id="portal_password" name="portal_password" type="text" class="form-control" minlength="8" autocomplete="new-password" value="{{ old('portal_password', $creating ? ($portalPassword ?? '') : '') }}" placeholder="{{ $creating ? '' : 'Kosongkan jika tidak diubah' }}"><div class="form-text">{{ $creating ? 'Dibuat acak otomatis dan ditampilkan agar mudah disalin.' : 'Password lama tidak dapat ditampilkan. Isi hanya jika ingin menggantinya.' }}</div></div>
        </div>
        <div class="form-text mt-2">Saat disimpan, username dan password PPPoE disinkronkan ke menu PPP → Secrets pada router yang dipilih.</div>
    </section>

    <section class="card card-body">
        <h2 class="h5">ODP / infrastruktur</h2>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="onu_record_id">Hubungkan ke ONU terdaftar</label><select id="onu_record_id" name="onu_record_id" class="form-select"><option value="">Tidak dihubungkan</option>@foreach($onus as $onu)<option value="{{ $onu->id }}" data-olt="{{ $onu->olt?->name }}" data-port="{{ $onu->pon_port }}" data-onu="{{ $onu->onu_id }}" data-sn="{{ $onu->serial_number }}" @selected((string)old('onu_record_id', $customer->onu?->id) === (string)$onu->id)>{{ $onu->olt?->name ?? 'OLT' }} — PON {{ $onu->pon_port }} / ONU {{ $onu->onu_id }}{{ $onu->serial_number ? ' / '.$onu->serial_number : '' }}</option>@endforeach</select><div class="form-text">Pilih inventaris ONU yang sudah dibuat agar relasi dan serial tersinkron.</div></div>
            <div class="col-md-6"><label class="form-label" for="olt_name">Nama OLT (input manual)</label><input id="olt_name" name="olt_name" class="form-control" value="{{ old('olt_name', $customer->olt_name) }}" placeholder="Hisfocus EPON / C-Data EPON"></div>
            <div class="col-md-3"><label class="form-label" for="pon_port">PON port</label><input id="pon_port" name="pon_port" class="form-control" value="{{ old('pon_port', $customer->pon_port) }}"></div>
            <div class="col-md-3"><label class="form-label" for="onu_id">ONU ID</label><input id="onu_id" name="onu_id" class="form-control" value="{{ old('onu_id', $customer->onu_id) }}"></div>
            <div class="col-md-3"><label class="form-label" for="onu_sn">Serial number ONU</label><input id="onu_sn" name="onu_sn" class="form-control" value="{{ old('onu_sn', $customer->onu_sn) }}"></div>
            <div class="col-md-4"><label class="form-label" for="modem_device">Modem / perangkat pelanggan</label><input id="modem_device" name="modem_device" class="form-control" value="{{ old('modem_device', $customer->modem_device) }}"></div>
            <div class="col-md-4"><label class="form-label" for="source_port">Eth / source port NAS-OLT</label><input id="source_port" name="source_port" class="form-control" value="{{ old('source_port', $customer->source_port) }}" placeholder="Port / interface sumber"></div>
        </div>
        <div class="form-text mt-2">Manajemen web OLT Hisfocus dan C-Data belum memiliki API yang dikonfigurasi; bagian ini menyimpan inventaris/manual, tidak mengubah konfigurasi OLT.</div>
    </section>

    <section class="card card-body">
        <h2 class="h5">FUP dan pengaturan lanjutan</h2>
        @if($fupState)<div class="alert {{ $fupState->limited ? 'alert-warning' : 'alert-secondary' }}">Pemakaian FUP bulan ini: {{ number_format($fupState->total_bytes / 1073741824, 2, ',', '.') }} GB {{ $fupState->limited ? '— profil FUP sedang diterapkan' : '— masih di bawah batas' }}. Sampel terakhir: {{ $fupState->last_sampled_at?->format('d/m/Y H:i') ?? 'belum ada' }}.</div>@elseif(!$creating)<div class="alert alert-secondary">Belum ada sampel pemakaian FUP bulan ini. Data muncul setelah scheduler FUP berhasil membaca sesi PPP aktif pelanggan.</div>@endif
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="fup_mode">Kebijakan FUP</label><select id="fup_mode" name="fup_mode" class="form-select" required><option value="inherit" @selected($fupMode === 'inherit')>Ikuti pengaturan paket</option><option value="on" @selected($fupMode === 'on')>Aktif untuk pelanggan ini</option><option value="off" @selected($fupMode === 'off')>Nonaktif untuk pelanggan ini</option></select><div class="form-text" id="package-fup-note"></div></div>
            <div class="col-md-4"><label class="form-label" for="fup_limit_gb">Batas pemakaian FUP (GB)</label><input id="fup_limit_gb" name="fup_limit_gb" type="number" min="0" step="0.1" class="form-control" value="{{ $fupLimitGb }}" placeholder="Kosongkan untuk mengikuti paket"></div>
            <div class="col-md-4"><label class="form-label" for="fup_speed_after">Profil setelah FUP (dari paket)</label><input id="fup_speed_after" name="fup_speed_after" class="form-control" value="{{ old('fup_speed_after', $customer->package?->fup_speed_after ?: $customer->fup_speed_after) }}" readonly><div class="form-text">Profil FUP mengikuti paket supaya konsisten dengan profil router.</div></div>
            <div class="col-md-4"><label class="form-label" for="status">Status layanan</label><select id="status" name="status" class="form-select">@foreach(['active'=>'Aktif','isolated'=>'Terisolir','suspended'=>'Ditangguhkan','terminated'=>'Berhenti','trial'=>'Uji coba'] as $value=>$label)<option value="{{ $value }}" @selected(old('status', $customer->status ?: 'active') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input type="hidden" name="is_auto_isolate" value="0"><input type="checkbox" name="is_auto_isolate" value="1" class="form-check-input" id="is_auto_isolate" @checked(old('is_auto_isolate', $customer->is_auto_isolate ?? true))><label for="is_auto_isolate" class="form-check-label">Izinkan isolir otomatis</label></div></div>
            <div class="col-md-4"><label class="form-label" for="activated_at">Tanggal aktif layanan</label><input id="activated_at" name="activated_at" type="date" class="form-control" value="{{ old('activated_at', $customer->activated_at?->format('Y-m-d') ?? ($creating ? now()->format('Y-m-d') : '')) }}"></div>
        </div>
        <div class="alert alert-warning mt-3 mb-0">FUP bekerja untuk PPPoE aktif yang bisa dibaca melalui RouterOS API. Profil normal dan profil setelah FUP harus sudah tersedia dengan nama yang sama di MikroTik. Perubahan paket pelanggan bisa memutus sesi PPP saat profil diterapkan.</div>
    </section>

    <section class="card card-body">
        <h2 class="h5">Siklus penagihan</h2>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label" for="due_day">Tanggal jatuh tempo bulanan (1–28) <span class="text-danger">*</span></label><input id="due_day" type="number" min="1" max="28" name="due_day" class="form-control" value="{{ old('due_day', $customer->due_day ?: 20) }}" required></div>
            <div class="col-md-4"><label class="form-label" for="grace_days">Toleransi sebelum isolir (hari)</label><input id="grace_days" type="number" min="0" max="31" name="grace_days" class="form-control" value="{{ old('grace_days', $customer->grace_days ?? 0) }}"></div>
            <div class="col-md-4"><div class="form-text mt-4">Tagihan aplikasi dibuat bulanan berdasarkan paket dan tanggal jatuh tempo. Pengingat mengikuti pengaturan WhatsApp global.</div></div>
        </div>
        <div class="alert alert-info mt-3 mb-0">Prorata, PPN per pelanggan, kode unik, biaya tambahan invoice berulang, pengirim WhatsApp pilihan, billing group, komisi/mitra, dan secret backup MikroTik belum memiliki konfigurasi aktif pada sistem ini, jadi tidak ditampilkan sebagai pilihan yang hanya terlihat seolah-olah bekerja.</div>
    </section>

    <div class="d-flex gap-2 mb-4"><button class="btn btn-primary" type="submit">{{ $creating ? 'Simpan pelanggan' : 'Simpan perubahan' }}</button><a href="{{ route('customers.index') }}" class="btn btn-outline-light">Batal</a></div>
</form>

@if(session('portal_password_created'))
<div class="alert alert-success d-flex flex-wrap align-items-center gap-2" role="status">
    <div><strong>Password portal dibuat.</strong> Simpan sekarang; untuk keamanan, password tidak bisa ditampilkan lagi setelah halaman ini.</div>
    <input id="created-portal-password" class="form-control" style="max-width: 280px" value="{{ session('portal_password_created') }}" readonly aria-label="Password portal yang dibuat">
    <button class="btn btn-outline-light" type="button" id="copy-portal-password">Salin password</button>
</div>
@endif
@if(session('warning'))<div class="alert alert-warning" role="alert">{{ session('warning') }}</div>@endif

<script>
(() => {
    const latitude = document.getElementById('latitude');
    const longitude = document.getElementById('longitude');
    const mapLink = document.getElementById('google-maps-link');
    const locationStatus = document.getElementById('location-status');
    const refreshMap = () => {
        if (!latitude.value || !longitude.value) { mapLink.classList.add('d-none'); return; }
        mapLink.href = `https://www.google.com/maps?q=${encodeURIComponent(latitude.value)},${encodeURIComponent(longitude.value)}`;
        mapLink.classList.remove('d-none');
    };
    latitude.addEventListener('input', refreshMap);
    longitude.addEventListener('input', refreshMap);
    refreshMap();
    const locationButton = document.getElementById('use-location');
    let lastGeocodeAt = 0;
    const reverseGeocode = async (lat, lon) => {
        const wait = Math.max(0, 1000 - (Date.now() - lastGeocodeAt));
        if (wait) await new Promise((resolve) => setTimeout(resolve, wait));
        lastGeocodeAt = Date.now();
        const url = new URL('https://nominatim.openstreetmap.org/reverse');
        url.search = new URLSearchParams({format: 'jsonv2', addressdetails: '1', lat, lon}).toString();
        const response = await fetch(url, {headers: {Accept: 'application/json'}});
        if (!response.ok) throw new Error('Layanan pencarian alamat tidak merespons.');
        const result = await response.json();
        const parts = result.address || {};
        const setIfPresent = (id, ...values) => {
            const value = values.find((item) => typeof item === 'string' && item.trim());
            if (value) document.getElementById(id).value = value;
        };
        setIfPresent('address', result.display_name);
        setIfPresent('rt', parts.rt);
        setIfPresent('rw', parts.rw);
        setIfPresent('village', parts.village, parts.suburb, parts.hamlet, parts.town);
        setIfPresent('district', parts.city_district, parts.district, parts.county);
        setIfPresent('city', parts.city, parts.municipality, parts.town, parts.state_district);
    };
    locationButton.addEventListener('click', () => {
        if (!navigator.geolocation) { locationStatus.textContent = 'Browser ini tidak mendukung akses lokasi.'; return; }
        locationButton.disabled = true;
        locationStatus.textContent = 'Meminta izin lokasi dari browser…';
        navigator.geolocation.getCurrentPosition(async ({coords}) => {
            latitude.value = coords.latitude.toFixed(7);
            longitude.value = coords.longitude.toFixed(7);
            refreshMap();
            locationStatus.textContent = 'Koordinat tersimpan. Mencari alamat…';
            try {
                await reverseGeocode(latitude.value, longitude.value);
                locationStatus.textContent = 'Lokasi dan alamat berhasil diisi. Silakan periksa kembali RT/RW dan alamat.';
            } catch (error) {
                locationStatus.textContent = 'Koordinat berhasil diisi, tetapi alamat tidak ditemukan otomatis. Isi alamat secara manual lalu coba lagi.';
            } finally {
                locationButton.disabled = false;
            }
        }, (error) => {
            locationStatus.textContent = error.code === 1 ? 'Izin lokasi ditolak. Izinkan lokasi di browser lalu coba lagi.' : 'Lokasi tidak tersedia. Periksa izin dan koneksi perangkat.';
            locationButton.disabled = false;
        }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 60000});
    });
    const copyButton = document.getElementById('copy-portal-password');
    copyButton?.addEventListener('click', async () => {
        const password = document.getElementById('created-portal-password');
        try {
            await navigator.clipboard.writeText(password.value);
            copyButton.textContent = 'Tersalin';
        } catch {
            password.select();
            document.execCommand('copy');
            copyButton.textContent = 'Tersalin';
        }
    });

    const service = document.getElementById('service_type');
    const creating = document.querySelector('form[data-creating]').dataset.creating === '1';
    const routerSelect = document.getElementById('router_id');
    const setServiceFields = () => {
        const isPppoe = service.value === 'pppoe';
        document.querySelectorAll('.pppoe-field').forEach((el) => el.classList.toggle('d-none', !isPppoe));
        document.querySelectorAll('.hotspot-field').forEach((el) => el.classList.toggle('d-none', isPppoe));
        document.getElementById('pppoe_username').required = isPppoe;
        routerSelect.required = isPppoe;
        document.getElementById('hotspot_username').required = !isPppoe;
        document.getElementById('pppoe_password').required = creating && isPppoe;
        document.getElementById('hotspot_password').required = creating && !isPppoe;
    };
    service.addEventListener('change', setServiceFields);
    setServiceFields();

    const packageSelect = document.getElementById('package_id');
    const fupNote = document.getElementById('package-fup-note');
    const packageMappingNote = document.getElementById('package-mapping-note');
    const filterPackagesByRouter = () => {
        const routerId = routerSelect.value;
        [...packageSelect.options].forEach((option) => {
            if (!option.value) return;
            option.hidden = Boolean(routerId && option.dataset.routerId && option.dataset.routerId !== routerId);
        });
        const selected = packageSelect.options[packageSelect.selectedIndex];
        if (selected?.hidden) packageSelect.value = '';
        if (!packageSelect.value) {
            const available = [...packageSelect.options].some((option) => option.value && !option.hidden);
            packageSelect.options[0].textContent = routerId ? (available ? 'Pilih paket untuk router ini' : 'Belum ada paket untuk router ini') : 'Pilih router lebih dulu';
        }
        renderFupNote();
    };
    const renderFupNote = () => {
        const selected = packageSelect.options[packageSelect.selectedIndex];
        const profileDisplay = document.getElementById('pppoe_profile_display');
        const profileInput = document.getElementById('pppoe_profile_normal');
        const fupProfile = document.getElementById('fup_speed_after');
        if (!selected || !selected.value) { fupNote.textContent = 'Pilih router dan paket untuk melihat pemetaan PPP dan kebijakan FUP.'; profileDisplay.value = ''; profileInput.value = ''; fupProfile.value = ''; packageMappingNote.textContent = ''; return; }
        packageMappingNote.textContent = selected.dataset.routerId ? '' : 'Paket lama ini belum ditautkan ke router. Admin perlu mengaturnya di menu PPPoE → Paket & Profil PPP sebelum data disimpan.';
        const enabled = selected.dataset.fupEnabled === '1';
        const limit = Number(selected.dataset.fupLimit || 0);
        fupNote.textContent = enabled ? `Paket ini mengaktifkan FUP${limit ? ` dengan batas ${(limit / 1073741824).toFixed(2)} GB` : ''}${selected.dataset.fupSpeed ? `; profil setelah batas: ${selected.dataset.fupSpeed}` : ''}.` : 'Paket ini tidak mengaktifkan FUP.';
        profileDisplay.value = selected.dataset.profile || '';
        profileInput.value = selected.dataset.profile || '';
        fupProfile.value = selected.dataset.fupSpeed || '';
    };
    routerSelect.addEventListener('change', filterPackagesByRouter);
    packageSelect.addEventListener('change', renderFupNote);
    filterPackagesByRouter();
    renderFupNote();

    document.getElementById('onu_record_id').addEventListener('change', (event) => {
        const option = event.target.options[event.target.selectedIndex];
        if (!option.value) return;
        document.getElementById('olt_name').value = option.dataset.olt || '';
        document.getElementById('pon_port').value = option.dataset.port || '';
        document.getElementById('onu_id').value = option.dataset.onu || '';
        document.getElementById('onu_sn').value = option.dataset.sn || '';
    });
})();
</script>
@endsection
