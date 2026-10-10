@extends('layouts.app')

@section('content')
<h1>MikroTik</h1>

<section class="card card-body my-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <h2 class="h5 mb-1">Script halaman isolir</h2>
            <p class="text-muted mb-0">Alamat halaman mengikuti APP_URL billing saat ini: <strong>{{ $billingHost }}/isolir</strong>.</p>
        </div>
        <button class="btn btn-primary" type="button" id="copy-isolation-script">Salin script</button>
    </div>

    @if($isolationMethod !== 'profile')
        <div class="alert alert-warning mt-3 mb-0">Metode isolir aplikasi saat ini bukan profile PPP. Script ini memakai profile; ubah BILLING_ISOLATION_METHOD ke profile sebelum digunakan.</div>
    @else
        <div class="alert alert-warning mt-3 mb-0">Script ini mengubah profile PPP, Web Proxy, NAT, dan filter input MikroTik. Tinjau port proxy 8097, backup, dan uji saat maintenance sebelum menjalankan. Rule lama vendor lain tidak diubah otomatis. Hanya HTTP port 80 yang dialihkan; HTTPS tidak dapat dialihkan transparan oleh Web Proxy RouterOS. Pastikan billing host publik aktif.</div>
    @endif

    <label for="isolation-script" class="form-label mt-3">RouterOS script</label>
    <textarea id="isolation-script" class="form-control font-monospace" rows="30" readonly spellcheck="false">{{ $isolationScript }}</textarea>
    <div id="copy-isolation-status" class="form-text mt-2" role="status">Script hanya disalin saat tombol ditekan. Menjalankannya di router akan mengubah konfigurasi; periksa isi dan backup terlebih dahulu.</div>
    <details class="mt-3">
        <summary class="text-info">Yang dikonfigurasi</summary>
        <ul class="mt-2 mb-0">
            <li>Profile PPP isolir menambahkan IP pelanggan ke address-list FIKSUM-ISOLIR.</li>
            <li>Filter input membatasi port Web Proxy 8097 hanya untuk IP di address-list isolir.</li>
            <li>Web Proxy mengizinkan host halaman billing dan mengalihkan HTTP lain.</li>
            <li>NAT mengarahkan HTTP pelanggan dalam address-list itu ke port proxy 8097.</li>
            <li>Rule lama MSRadius atau vendor lain tidak dinonaktifkan otomatis.</li>
        </ul>
    </details>
</section>

<div class="row g-3 mt-1">
    <div class="col-lg-5">
        <form method="POST" action="{{ route('routers.store') }}" class="card card-body">
            @csrf
            <h2 class="h5">Tambah Router</h2>
            <input name="name" class="form-control mb-2" placeholder="Nama Router" required>
            <input name="host" class="form-control mb-2" placeholder="IP / Host" required>
            <input name="traffic_interface" class="form-control mb-2" placeholder="Interface WAN untuk grafik trafik (contoh: ether1)">
            <input name="port" id="router-port" type="number" value="8728" class="form-control mb-2">
            <input name="username" class="form-control mb-2" placeholder="API Username" required>
            <input name="password" type="password" class="form-control mb-2" placeholder="API Password" required>
            <div class="form-check">
                <input name="ssl" value="1" type="checkbox" class="form-check-input" id="ssl" onchange="document.getElementById('router-port').value = this.checked ? 8729 : 8728">
                <label for="ssl" class="form-check-label">API-SSL (port 8729)</label>
            </div>
            <small class="text-muted">Port otomatis mengikuti pilihan: API biasa 8728, API-SSL 8729. API-SSL harus diaktifkan juga di MikroTik.</small>
            <button class="btn btn-primary mt-3">Simpan</button>
        </form>
    </div>

    <div class="col-lg-7">
        <div class="table-responsive shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Nama</th><th>Host</th><th>Status</th><th>Terakhir</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse($routers as $r)
                    <tr>
                        <td>{{ $r->name }}</td>
                        <td>{{ $r->host }}:{{ $r->port }}</td>
                        <td>{{ $r->last_seen_at && $r->last_seen_at->gt(now()->subMinutes(10)) ? 'Online' : 'Unknown' }}</td>
                        <td>{{ $r->last_seen_at }}</td>
                        <td class="d-flex flex-wrap gap-1">
                            <details class="w-100">
                                <summary class="btn btn-sm btn-outline-secondary">Edit data router</summary>
                                <form method="POST" action="{{ route('routers.update', $r) }}" class="card card-body mt-2 d-grid gap-2">
                                    @csrf
                                    @method('PUT')
                                    <label class="form-label mb-0" for="router-name-{{ $r->id }}">Nama router</label>
                                    <input id="router-name-{{ $r->id }}" name="name" value="{{ $r->name }}" class="form-control form-control-sm" maxlength="100" required>
                                    <label class="form-label mb-0" for="router-host-{{ $r->id }}">IP / Host</label>
                                    <input id="router-host-{{ $r->id }}" name="host" value="{{ $r->host }}" class="form-control form-control-sm" maxlength="255" required>
                                    <label class="form-label mb-0" for="router-port-{{ $r->id }}">Port API</label>
                                    <input id="router-port-{{ $r->id }}" name="port" type="number" min="1" max="65535" value="{{ $r->port }}" class="form-control form-control-sm" required>
                                    <label class="form-label mb-0" for="router-user-{{ $r->id }}">API username</label>
                                    <input id="router-user-{{ $r->id }}" name="username" value="{{ $r->username }}" class="form-control form-control-sm" maxlength="100" required>
                                    <label class="form-label mb-0" for="router-password-{{ $r->id }}">Password baru (opsional)</label>
                                    <input id="router-password-{{ $r->id }}" name="password" type="password" class="form-control form-control-sm" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                                    <label class="form-label mb-0" for="router-notes-{{ $r->id }}">Catatan</label>
                                    <textarea id="router-notes-{{ $r->id }}" name="notes" class="form-control form-control-sm" rows="2">{{ $r->notes }}</textarea>
                                    <div class="d-flex flex-wrap gap-3">
                                        <div class="form-check">
                                            <input type="hidden" name="ssl" value="0">
                                            <input class="form-check-input" id="router-ssl-{{ $r->id }}" type="checkbox" name="ssl" value="1" @checked($r->ssl)>
                                            <label class="form-check-label" for="router-ssl-{{ $r->id }}">API-SSL</label>
                                        </div>
                                        <div class="form-check">
                                            <input type="hidden" name="enabled" value="0">
                                            <input class="form-check-input" id="router-enabled-{{ $r->id }}" type="checkbox" name="enabled" value="1" @checked($r->enabled)>
                                            <label class="form-check-label" for="router-enabled-{{ $r->id }}">Aktifkan router</label>
                                        </div>
                                    </div>
                                    <button class="btn btn-sm btn-primary">Simpan perubahan</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('routers.traffic-interface', $r) }}" class="d-inline-flex gap-1">
                                @csrf @method('PATCH')
                                <input name="traffic_interface" value="{{ $r->traffic_interface }}" placeholder="WAN interface" class="form-control form-control-sm" required>
                                <button class="btn btn-sm btn-outline-info">Simpan interface</button>
                            </form>
                            <form method="POST" action="{{ route('routers.test', $r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">Test</button></form>
                            <form method="POST" action="{{ route('routers.destroy', $r) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus router?')">Hapus</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center p-4">Belum ada router.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    const button = document.getElementById('copy-isolation-script');
    const textarea = document.getElementById('isolation-script');
    const status = document.getElementById('copy-isolation-status');
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(textarea.value);
            status.textContent = 'Script berhasil disalin. Tempel di Terminal MikroTik.';
        } catch {
            textarea.focus();
            textarea.select();
            const copied = document.execCommand('copy');
            status.textContent = copied
                ? 'Script berhasil disalin. Tempel di Terminal MikroTik.'
                : 'Pilih seluruh isi script lalu salin manual.';
        }
    });
})();
</script>
@endsection
