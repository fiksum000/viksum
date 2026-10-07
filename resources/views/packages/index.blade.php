@extends('layouts.app')

@section('content')
<div class="mb-3">
    <h1 class="mb-1">Paket Internet</h1>
    <p class="text-muted mb-0">Paket billing ditautkan langsung ke router dan profil PPP yang sudah ada. Form ini hanya membaca profil dari MikroTik; tidak membuat atau mengubah profil router.</p>
</div>

@php
    $packagesNeedingMapping = $packages->filter(fn ($package) =>
        !$package->router_id || blank($package->normal_profile) || ($package->fup_enabled && blank($package->fup_speed_after))
    );
@endphp
@if($packagesNeedingMapping->isNotEmpty())
    <div class="alert alert-warning" role="alert">
        <strong>Pemetaan paket belum lengkap.</strong> Paket di bawah ini belum siap dipakai untuk provisioning PPP atau FUP sampai dipetakan ke profil yang benar-benar tersedia di router.
        <ul class="mb-0 mt-2">
            @foreach($packagesNeedingMapping as $package)
                <li>
                    <strong>{{ $package->name }}</strong>:
                    @if(!$package->router_id)
                        Router belum ditautkan; profil normal “{{ $package->normal_profile }}” belum diverifikasi di router.
                        @if($package->fup_enabled && filled($package->fup_speed_after))
                            Profil FUP “{{ $package->fup_speed_after }}” juga belum diverifikasi di router.
                        @elseif($package->fup_enabled)
                            Profil setelah FUP belum dipilih.
                        @endif
                    @elseif(blank($package->normal_profile))
                        Profil normal belum dipilih.
                    @endif
                    @if($package->router_id && $package->fup_enabled && blank($package->fup_speed_after))
                        Profil setelah FUP belum dipilih.
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3 mt-1">
    <div class="col-lg-5">
        <form method="POST" action="{{ route('packages.store') }}" class="card card-body" id="new-package-form">
            @csrf
            <h2 class="h5">Tambah paket dan pemetaan PPP</h2>
            <label class="form-label" for="new-name">Nama paket billing</label>
            <input id="new-name" name="name" class="form-control mb-2" value="{{ old('name') }}" placeholder="Contoh: Internet 20 Mbps" required>
            <label class="form-label" for="new-price">Harga bulanan</label>
            <input id="new-price" name="price" type="number" min="0" class="form-control mb-2" value="{{ old('price') }}" placeholder="Harga" required>
            <label class="form-label" for="new-router">Router PPP</label>
            <select id="new-router" name="router_id" class="form-select mb-2 router-select" required>
                <option value="">Pilih router</option>
                @foreach($routers as $router)<option value="{{ $router->id }}">{{ $router->name }} — {{ $router->host }}:{{ $router->port }}</option>@endforeach
            </select>
            <label class="form-label" for="new-profile">Profil PPP di router</label>
            <select id="new-profile" name="normal_profile" class="form-select mb-2 profile-select" data-selected="{{ old('normal_profile') }}" required disabled>
                <option value="">Pilih router lebih dulu</option>
            </select>
            <div class="form-text mb-2 profile-status" role="status">Profil akan dimuat dari router secara read-only.</div>
            <label class="form-label" for="new-speed">Kecepatan (catatan)</label>
            <input id="new-speed" name="normal_speed" class="form-control mb-2" value="{{ old('normal_speed') }}" placeholder="Opsional, contoh 20 Mbps">
            <label class="form-label" for="new-fup-limit">Batas FUP (GB)</label>
            <input id="new-fup-limit" type="number" min="0" step="0.1" class="form-control mb-2" placeholder="Opsional">
            <input type="hidden" name="fup_limit_bytes" id="new-fup-bytes" value="{{ old('fup_limit_bytes') }}">
            <label class="form-label" for="new-fup-profile">Profil PPP setelah FUP</label>
            <select id="new-fup-profile" name="fup_speed_after" class="form-select mb-2 fup-profile-select" data-selected="{{ old('fup_speed_after') }}" disabled><option value="">Pilih router lebih dulu</option></select>
            <input name="priority" type="hidden" value="8">
            <div class="form-check"><input name="fup_enabled" value="0" type="hidden"><input name="fup_enabled" value="1" type="checkbox" class="form-check-input" id="new-fup"><label class="form-check-label" for="new-fup">Aktifkan FUP</label></div>
            <button class="btn btn-primary mt-3">Simpan paket</button>
        </form>
    </div>

    <div class="col-lg-7">
        <div class="card card-body">
            <h2 class="h5">Paket billing ↔ profil PPP</h2>
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr><th>Paket</th><th>Router / Profil PPP</th><th>FUP</th><th></th></tr></thead>
                <tbody>
                @forelse($packages as $package)
                    <tr>
                        <td><strong>{{ $package->name }}</strong><br><span class="text-muted">Rp {{ number_format($package->price, 0, ',', '.') }}/bulan</span></td>
                        <td>{{ $package->router?->name ?? 'Belum ditautkan' }}<br><code>{{ $package->normal_profile }}</code>@if(!$package->router_id)<br><span class="text-warning">Belum diverifikasi di router</span>@endif</td>
                        <td>
                            @if(!$package->fup_enabled)
                                Tidak aktif
                            @elseif(blank($package->fup_speed_after))
                                <span class="text-warning">Aktif — profil FUP belum dipilih</span>
                            @elseif(!$package->router_id)
                                <span class="text-warning">Aktif — <code>{{ $package->fup_speed_after }}</code> belum diverifikasi di router</span>
                            @else
                                Aktif<br><code>{{ $package->fup_speed_after }}</code>
                            @endif
                        </td>
                        <td><details><summary class="btn btn-sm btn-outline-light">Edit</summary>
                            <form method="POST" action="{{ route('packages.update', $package) }}" class="card card-body mt-2 package-edit-form" style="min-width: 280px">
                                @csrf @method('PUT')
                                <label class="form-label">Nama paket</label><input name="name" class="form-control mb-2" value="{{ $package->name }}" required>
                                <label class="form-label">Harga bulanan</label><input name="price" type="number" min="0" class="form-control mb-2" value="{{ $package->price }}" required>
                                <label class="form-label">Router PPP</label><select name="router_id" class="form-select mb-2 router-select" required>
                                    <option value="">Pilih router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected($package->router_id === $router->id)>{{ $router->name }} — {{ $router->host }}</option>@endforeach
                                </select>
                                <label class="form-label">Profil PPP</label><select name="normal_profile" class="form-select mb-1 profile-select" data-selected="{{ $package->normal_profile }}" required disabled><option value="">Memuat profil…</option></select>
                                <div class="form-text mb-2 profile-status" role="status"></div>
                                <label class="form-label">Kecepatan (catatan)</label><input name="normal_speed" class="form-control mb-2" value="{{ $package->normal_speed }}">
                                <label class="form-label">Batas FUP (GB)</label><input type="number" min="0" step="0.1" class="form-control mb-2 fup-gb" value="{{ $package->fup_limit_bytes ? round($package->fup_limit_bytes / 1073741824, 2) : '' }}">
                                <input type="hidden" name="fup_limit_bytes" class="fup-bytes" value="{{ $package->fup_limit_bytes }}">
                                <label class="form-label">Profil PPP setelah FUP</label><select name="fup_speed_after" class="form-select mb-2 fup-profile-select" data-selected="{{ $package->fup_speed_after }}" disabled><option value="">Memuat profil…</option></select>
                                <input name="priority" type="hidden" value="{{ $package->priority }}">
                                <div class="form-check"><input name="fup_enabled" value="0" type="hidden"><input name="fup_enabled" value="1" type="checkbox" class="form-check-input" id="fup-{{ $package->id }}" @checked($package->fup_enabled)><label class="form-check-label" for="fup-{{ $package->id }}">Aktifkan FUP</label></div>
                                <button class="btn btn-primary btn-sm mt-2">Simpan pemetaan</button>
                            </form>
                        </details>
                        <form method="POST" action="{{ route('packages.destroy', $package) }}" class="mt-1">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus paket ini?')">Hapus</button></form></td>
                    </tr>
                @empty<tr><td colspan="4" class="text-center text-muted p-4">Belum ada paket.</td></tr>@endforelse
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<script>
(() => {
    const profileUrl = @json(url('/routers'));
    document.querySelectorAll('.router-select').forEach((routerSelect) => {
        const form = routerSelect.closest('form');
        const profileSelect = form.querySelector('.profile-select');
        const status = form.querySelector('.profile-status');
        const loadProfiles = async () => {
            const previous = profileSelect.dataset.selected || profileSelect.value;
            profileSelect.replaceChildren(new Option(routerSelect.value ? 'Memuat profil…' : 'Pilih router lebih dulu', ''));
            profileSelect.disabled = true;
            if (!routerSelect.value) { status.textContent = 'Pilih router aktif untuk melihat profil yang tersedia.'; return; }
            status.textContent = 'Membaca daftar profil dari router…';
            try {
                const response = await fetch(profileUrl + '/' + encodeURIComponent(routerSelect.value) + '/ppp-profiles/options', {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Profil tidak dapat dibaca.');
                profileSelect.replaceChildren(new Option('Pilih profil PPP', ''));
                payload.profiles.forEach((name) => profileSelect.add(new Option(name, name, false, name === previous)));
                profileSelect.disabled = false;
                const fupSelect = form.querySelector('.fup-profile-select');
                if (fupSelect) {
                    const fupPrevious = fupSelect.dataset.selected || fupSelect.value;
                    fupSelect.replaceChildren(new Option('Pilih profil setelah FUP (opsional)', ''));
                    payload.profiles.forEach((name) => fupSelect.add(new Option(name, name, false, name === fupPrevious)));
                    fupSelect.disabled = false;
                }
                if (fupSelect?.dataset.selected && !payload.profiles.includes(fupSelect.dataset.selected)) status.textContent = 'Profil FUP “' + fupSelect.dataset.selected + '” tidak ditemukan di router ini; pilih profil yang tersedia.';
                else if (previous && !payload.profiles.includes(previous)) status.textContent = 'Profil tersimpan “' + previous + '” tidak ditemukan di router ini. Pilih profil yang tersedia.';
                else status.textContent = payload.profiles.length ? payload.profiles.length + ' profil tersedia; data hanya dibaca dari MikroTik.' : 'Router belum memiliki profil PPP.';
            } catch (error) {
                profileSelect.replaceChildren(new Option('Profil gagal dimuat', ''));
                status.textContent = error.message;
            }
        };
        routerSelect.addEventListener('change', () => {
            profileSelect.dataset.selected = '';
            const fupSelect = form.querySelector('.fup-profile-select');
            if (fupSelect) fupSelect.dataset.selected = '';
            loadProfiles();
        });
        loadProfiles();
    });

    document.querySelectorAll('form').forEach((form) => {
        const gb = form.querySelector('.fup-gb') || form.querySelector('#new-fup-limit');
        const bytes = form.querySelector('.fup-bytes') || form.querySelector('#new-fup-bytes');
        if (!gb || !bytes) return;
        const syncBytes = () => { bytes.value = gb.value === '' ? '' : String(Math.round(Number(gb.value) * 1073741824)); };
        gb.addEventListener('input', syncBytes);
        syncBytes();
    });
})();
</script>
@endsection
