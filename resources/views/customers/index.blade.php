@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1>Pelanggan</h1>
    <div class="d-flex gap-2 flex-wrap">
        @if(in_array($billingUser?->role,['super_admin','admin','finance'],true))
            <a class="btn btn-outline-success" href="{{route('customers.export',request()->query())}}">Export XLSX</a>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin'],true))
            <form method="POST" action="{{route('customers.import')}}" enctype="multipart/form-data">
                @csrf
                <input id="customer-import-file" type="file" name="file" accept=".xlsx,.xls,.csv" class="visually-hidden" aria-label="Pilih file pelanggan XLSX atau CSV" aria-describedby="customer-import-help" required>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <label for="customer-import-file" class="btn btn-outline-secondary mb-0">Pilih file</label>
                    <span id="customer-import-name" class="small text-muted" aria-live="polite">Belum ada file dipilih</span>
                    <button id="customer-import-submit" type="submit" class="btn btn-outline-info" disabled>Import</button>
                </div>
                <div id="customer-import-help" class="form-text">XLSX, XLS, atau CSV. Tinjau file sebelum menekan Import.</div>
            </form>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin','operator'],true))
            <a class="btn btn-primary" href="{{route('customers.create')}}">+ Tambah</a>
        @endif
    </div>
</div>
@if(in_array($billingUser?->role,['super_admin','admin'],true))
<script>
(() => {
    const input = document.getElementById('customer-import-file');
    const filename = document.getElementById('customer-import-name');
    const submit = document.getElementById('customer-import-submit');
    if (!input || !filename || !submit) return;
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        filename.textContent = file ? file.name : 'Belum ada file dipilih';
        submit.disabled = !file;
    });
})();
</script>
@endif
<form class="row g-2 mb-3" method="GET" action="{{route('customers.index')}}">
    <div class="col-lg-4 col-md-6"><input name="search" type="search" maxlength="120" class="form-control" placeholder="Nama / ID / username / WhatsApp" value="{{request('search')}}" aria-label="Cari pelanggan"></div>
    <div class="col-lg-2 col-md-3"><select name="service_type" class="form-select" aria-label="Jenis layanan"><option value="">Semua layanan</option><option value="pppoe" @selected(request('service_type')==='pppoe')>PPPoE</option><option value="hotspot" @selected(request('service_type')==='hotspot')>Hotspot</option></select></div>
    <div class="col-lg-2 col-md-3"><select name="status" class="form-select" aria-label="Status layanan"><option value="">Semua status</option>@foreach(['active','isolated','suspended','terminated','trial'] as $s)<option value="{{$s}}" @selected(request('status')===$s)>{{$s}}</option>@endforeach</select></div>
    <div class="col-lg-2 col-md-4"><select name="package_id" class="form-select" aria-label="Paket"><option value="">Semua paket</option>@foreach($packages as $package)<option value="{{$package->id}}" @selected((string)request('package_id')===(string)$package->id)>{{$package->name}}</option>@endforeach</select></div>
    <div class="col-lg-2 col-md-4 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="{{route('customers.index')}}">Reset</a></div>
</form>
<div class="d-flex flex-wrap gap-2 align-items-center small mb-3" aria-label="Keterangan warna status pelanggan">
    <span class="badge rounded-pill" style="background:#991b1b;color:#fff">Isolir</span>
    <span class="badge rounded-pill" style="background:#fff;color:#111827">Lunas</span>
    <span class="badge rounded-pill" style="background:#facc15;color:#422006">Masa tagihan</span>
    <span class="badge rounded-pill" style="background:#166534;color:#fff">Online di MikroTik</span>
    <span class="badge rounded-pill" style="background:#374151;color:#fff">Offline di MikroTik</span>
    <span class="text-muted">↓ download / ↑ upload saat ini, diperbarui tiap 30 detik untuk pelanggan PPPoE dan Hotspot yang online.</span>
</div>
<div class="table-responsive shadow-sm">
    <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="text-center"><tr><th class="text-nowrap text-center">ID</th><th>Nama</th><th class="text-nowrap text-center">WhatsApp</th><th>Paket</th><th>Layanan</th><th>Router</th><th class="text-nowrap">Status</th><th class="text-nowrap">Tagihan {{ $billingPeriod }}</th><th class="text-nowrap">MikroTik</th><th class="text-nowrap text-center">Trafik</th><th class="text-nowrap text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse($customers as $c)
            @php
                $currentInvoice = $c->invoices->first();
                $serviceStatus = match($c->status) {
                    'active' => ['Aktif di billing', 'success'],
                    'isolated' => ['Isolir', 'danger'],
                    'trial' => ['Uji coba', 'info'],
                    'suspended' => ['Ditangguhkan', 'secondary'],
                    'terminated' => ['Berhenti', 'secondary'],
                    default => [$c->status, 'secondary'],
                };
                $billingStatus = $c->status === 'isolated'
                    ? ['Isolir', 'background:#991b1b;color:#fff']
                    : ($currentInvoice?->status === 'paid'
                        ? ['Lunas', 'background:#fff;color:#111827']
                        : ($currentInvoice?->status === 'unpaid'
                            ? ['Masa tagihan', 'background:#facc15;color:#422006']
                            : ['Belum ditagih', 'background:#374151;color:#fff']));
                $connectionStatus = match($c->live_connection_status) {
                    'online' => ['Online', 'background:#166534;color:#fff'],
                    'offline' => ['Offline', 'background:#374151;color:#fff'],
                    'unknown' => ['Tidak diketahui', 'background:#713f12;color:#fff'],
                    default => ['Belum disetel', 'background:#374151;color:#fff'],
                };
                $username = $c->service_type === 'pppoe' ? $c->pppoe_username : $c->hotspot_username;
            @endphp
            <tr>
                <td class="text-nowrap text-center">{{$c->customer_code}}</td>
                <td>{{$c->name}}</td>
                <td class="text-nowrap text-center">{{$c->whatsapp_number?:$c->phone}}</td>
                <td>{{$c->package?->name}}</td>
                <td><span class="badge text-bg-secondary">{{strtoupper($c->service_type)}}</span><div class="small text-muted">{{$username?:'Username belum diatur'}}</div></td>
                <td>{{$c->router?->name??'—'}}</td>
                <td class="text-nowrap text-center"><span class="badge text-bg-{{$serviceStatus[1]}}">{{$serviceStatus[0]}}</span></td>
                <td>
                    <span class="badge rounded-pill" style="{{$billingStatus[1]}}">{{$billingStatus[0]}}</span>
                    @if($currentInvoice)<div class="small text-muted mt-1">Jatuh tempo {{$currentInvoice->due_date?->format('d-m-Y')}}</div>@endif
                </td>
                <td>
                    <span class="badge rounded-pill" style="{{$connectionStatus[1]}}">{{$connectionStatus[0]}}</span>
                    @if($c->live_connection_status==='unknown')<div class="small text-muted mt-1">Router tidak dapat dibaca</div>@endif
                </td>
                <td>@if(in_array($c->service_type,['pppoe','hotspot'],true) && $c->live_connection_status==='online')<span class="badge rounded-pill bg-secondary-subtle text-light border border-secondary-subtle" data-customer-traffic-id="{{$c->id}}" aria-label="Menunggu sampel trafik">Mengukur…</span>@elseif(in_array($c->service_type,['pppoe','hotspot'],true) && $c->live_connection_status==='offline')<span class="small text-muted">Offline</span>@elseif(in_array($c->service_type,['pppoe','hotspot'],true) && $c->live_connection_status==='unknown')<span class="small text-muted">Tidak diketahui</span>@else<span class="small text-muted">—</span>@endif</td>
                <td class="text-nowrap text-center">
                    @if(in_array($billingUser?->role,['super_admin','admin','operator','technician'],true))
                        <a href="{{route('customers.show',$c)}}" class="btn btn-sm btn-outline-primary">Detail</a>
                    @endif
                    @if(in_array($billingUser?->role,['super_admin','admin','operator'],true))
                        <a href="{{route('customers.edit',$c)}}" class="btn btn-sm btn-outline-secondary">Edit</a>
                    @endif
                    @if(in_array($billingUser?->role,['super_admin','admin'],true))
                        <form method="POST" action="{{route('customers.destroy',$c)}}" class="d-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Hapus permanen pelanggan {{ $c->customer_code }} — {{ $c->name }}? Hanya pelanggan Berhenti atau Uji coba tanpa riwayat tagihan yang dapat dihapus. Akun MikroTik harus berhasil dibersihkan lebih dahulu.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger" @disabled(!in_array($c->status, ['terminated', 'trial'], true) || (bool) $c->invoices_exists) title="{{ (bool) $c->invoices_exists ? 'Pelanggan memiliki riwayat tagihan; jangan hapus permanen.' : (in_array($c->status, ['terminated', 'trial'], true) ? 'Pelanggan memenuhi syarat untuk diperiksa sebelum dihapus.' : 'Hentikan layanan terlebih dahulu sebelum menghapus permanen.') }}">Hapus</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="text-center p-4">Belum ada pelanggan.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{$customers->links()}}</div>@if($customers->getCollection()->contains(fn ($customer) => in_array($customer->service_type,['pppoe','hotspot'],true) && $customer->live_connection_status==='online'))
<script>
(() => {
    const endpoint = @json(route('customers.traffic'));
    const nodes = [...document.querySelectorAll('[data-customer-traffic-id]')];
    if (!nodes.length) return;
    const formatRate = (bps) => {
        if (!Number.isFinite(Number(bps)) || Number(bps) < 0) return '—';
        const value = Number(bps);
        if (value >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
        if (value >= 1_000) return `${(value / 1_000).toFixed(0)}K`;
        return `${Math.round(value)}bps`;
    };
    const setState = (node, state) => {
        const labels = {sampling: 'Mengukur…', unavailable: 'Tidak tersedia', unknown: 'Tidak diketahui', offline: 'Offline'};
        node.textContent = labels[state] || 'Tidak tersedia';
        node.title = 'Data laju download/upload PPPoE';
        node.className = `${state === 'sampling' ? 'bg-secondary-subtle text-light border border-secondary-subtle' : 'bg-dark text-light border border-secondary'} badge rounded-pill`;
    };
    const refresh = async () => {
        try {
            const response = await fetch(`${endpoint}${window.location.search}`, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) throw new Error('Traffic sample unavailable');
            const payload = await response.json();
            for (const node of nodes) {
                const sample = payload.customers?.[node.dataset.customerTrafficId];
                if (!sample || sample.state !== 'online') { setState(node, sample?.state || 'unknown'); continue; }
                node.textContent = `↓${formatRate(sample.download_bps)} / ↑${formatRate(sample.upload_bps)}`;
                node.title = 'Download / upload saat ini; diperbarui tiap 30 detik';
                node.className = 'badge rounded-pill bg-success-subtle text-light border border-success-subtle';
            }
        } catch (_) { for (const node of nodes) setState(node, 'unknown'); }
    };
    refresh();
    window.setInterval(refresh, 30_000);
})();
</script>
@endif

@endsection

