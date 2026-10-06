@extends('layouts.app')

@section('content')
@php
    $canManageCustomers = in_array($billingUser?->role, ['super_admin', 'admin', 'operator'], true);
    $canManageNetwork = in_array($billingUser?->role, ['super_admin', 'admin', 'technician'], true);
@endphp

<style>
    .dash-title { letter-spacing: -.045em;font-weight:760 }
    .dash-panel { border: 1px solid #2b352d; border-radius: 16px; background: linear-gradient(145deg,#141a15,#0e120f); overflow: hidden; }
    .dash-panel-title { padding: .9rem 1.05rem; border-bottom: 1px solid #2b352d; background: rgba(255,255,255,.035); font-size: .94rem; font-weight: 700; }
    .dash-tile { min-height: 108px; border:1px solid rgba(255,255,255,.11);border-radius: 13px; padding: 1rem; color: #fff; display: flex; flex-direction: column; justify-content: space-between;transition:transform .16s,border-color .16s; }
    .dash-tile:hover {transform:translateY(-2px);border-color:rgba(255,255,255,.18)}
    .dash-tile .dash-value { font-size: clamp(1.55rem, 3vw, 2.15rem); font-weight: 760; line-height: 1;letter-spacing:-.04em }
    .dash-tile .dash-label { font-size: .8rem; color:rgba(255,255,255,.78); }
    .dash-blue { background: linear-gradient(145deg,#194955,#123941); } .dash-green { background: linear-gradient(145deg,#285b3b,#1a422a); }
    .dash-yellow { background: linear-gradient(145deg,#5a4a1d,#3d3215); } .dash-red { background: linear-gradient(145deg,#5a3036,#402328); }
    .dash-slate { background: linear-gradient(145deg,#303b33,#202923); }
    .dash-system { min-height: 112px; background: linear-gradient(145deg,#171f19,#101611); border: 1px solid #2b352d; }
    #traffic-chart { width: 100%; height: 260px; display: block; }
    @media (max-width: 575.98px) { #traffic-chart { height: 205px; } }
</style>

<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
    <div><h1 class="dash-title h2 mb-1">Dashboard</h1><div class="text-muted">Ringkasan Billing FIKSUM · {{ $now->translatedFormat('l, d F Y H:i') }}</div></div>
    <span class="badge text-bg-dark border border-secondary">Siklus FUP {{ $fupCycle }} · reset tanggal 10</span>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="dash-system rounded p-3 h-100 d-flex gap-3 align-items-center">
            <div class="fs-1" aria-hidden="true">▦</div>
            <div><div class="fw-semibold">Waktu Billing</div><div>{{ $now->format('d/m/Y H:i:s') }}</div><div class="small text-muted">Zona waktu {{ config('billing.timezone') }}</div></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="dash-system rounded p-3 h-100 d-flex gap-3 align-items-center">
            <div class="fs-1" aria-hidden="true">ⓘ</div>
            <div>
                <div class="fw-semibold">Router terdaftar</div>
                <div>{{ $stats['routers_online'] }} online dari {{ $stats['routers'] }} · {{ $stats['routers_offline'] }} belum terpantau</div>
                <div class="small text-muted">Status berdasarkan pemeriksaan koneksi terakhir yang tersimpan.</div>
            </div>
        </div>
    </div>
</div>

<section class="dash-panel mb-4">
    <div class="dash-panel-title">◉ Hotspot <span class="small text-muted fw-normal">(data Billing)</span></div>
    <div class="row g-2 p-3">
        <div class="col-6 col-xl-3"><div class="dash-tile dash-blue"><div class="dash-value">{{ number_format($stats['hotspot_active']) }}</div><div class="dash-label">Pelanggan Hotspot aktif</div></div></div>
        <div class="col-6 col-xl-3"><div class="dash-tile dash-green"><div class="dash-value">{{ number_format($stats['hotspot_total']) }}</div><div class="dash-label">Akun pelanggan Hotspot</div></div></div>
        <div class="col-6 col-xl-3"><div class="dash-tile dash-yellow"><div class="dash-value">{{ number_format($stats['hotspot_vouchers']) }}</div><div class="dash-label">Voucher aktif tersimpan</div></div></div>
        <div class="col-6 col-xl-3">@if($canManageNetwork)<a href="{{ route('hotspot.index') }}" class="dash-tile dash-red text-decoration-none"><div class="dash-value">＋</div><div class="dash-label">Kelola Hotspot &amp; voucher</div></a>@else<div class="dash-tile dash-slate"><div class="dash-value">{{ number_format($stats['hotspot_active']) }}</div><div class="dash-label">Hotspot aktif</div></div>@endif</div>
    </div>
</section>

<section class="dash-panel mb-4">
    <div class="dash-panel-title">◉ PPPoE <span class="small text-muted fw-normal">(status pelanggan di Billing; bukan sesi live router)</span></div>
    <div class="row g-2 p-3">
        <div class="col-6 col-xl-3"><div class="dash-tile dash-blue"><div class="dash-value">{{ number_format($stats['pppoe_active']) }}</div><div class="dash-label">Layanan PPPoE aktif</div></div></div>
        <div class="col-6 col-xl-3"><div class="dash-tile dash-red"><div class="dash-value">{{ number_format($stats['pppoe_isolated']) }}</div><div class="dash-label">Pelanggan terisolir</div></div></div>
        <div class="col-6 col-xl-3"><div class="dash-tile dash-green"><div class="dash-value">{{ number_format($stats['pppoe_total']) }}</div><div class="dash-label">Akun PPPoE tersimpan di Billing</div></div></div>
        <div class="col-6 col-xl-3">@if($canManageCustomers)<a href="{{ route('customers.create') }}" class="dash-tile dash-yellow text-decoration-none"><div class="dash-value">＋</div><div class="dash-label">Tambah pelanggan</div></a>@else<div class="dash-tile dash-slate"><div class="dash-value">{{ number_format($stats['active']) }}</div><div class="dash-label">Semua layanan aktif</div></div>@endif</div>
    </div>
</section>

@if($canManageNetwork)
<section class="dash-panel mb-4">
    <div class="dash-panel-title d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>▥ Trafik WAN <span class="small text-muted fw-normal">(pembacaan RouterOS saja)</span></span>
        <div class="d-flex align-items-center gap-2"><label class="small fw-normal" for="traffic-router">Router</label><select id="traffic-router" class="form-select form-select-sm" style="width:auto" aria-label="Pilih router trafik"></select><span id="traffic-updated" class="small text-muted">Menunggu data…</span></div>
    </div>
    <div class="p-3">
        <div class="row g-3 mb-2">
            <div class="col-6"><div class="small text-muted">Download / RX</div><div id="traffic-rx" class="fs-4 fw-bold">—</div></div>
            <div class="col-6"><div class="small text-muted">Upload / TX</div><div id="traffic-tx" class="fs-4 fw-bold">—</div></div>
        </div>
        <svg id="traffic-chart" viewBox="0 0 900 250" role="img" aria-label="Grafik download dan upload WAN" preserveAspectRatio="none">
            <path d="M58 15V210H890" fill="none" stroke="#495057" stroke-width="1"/>
            <path d="M58 60H890M58 110H890M58 160H890" fill="none" stroke="#282d33" stroke-width="1"/>
            <path id="traffic-rx-line" fill="none" stroke="#22b8e6" stroke-width="3" stroke-linejoin="round"/>
            <path id="traffic-tx-line" fill="none" stroke="#ffc107" stroke-width="3" stroke-linejoin="round"/>
            <text id="traffic-scale" x="5" y="20" fill="#adb5bd" font-size="12">Mbps</text>
        </svg>
        <div class="d-flex flex-wrap gap-3 small mt-2"><span class="text-info">● Download</span><span class="text-warning">● Upload</span><span id="traffic-status" class="text-muted ms-auto" role="status">Menyiapkan grafik…</span></div>
        <div class="small text-muted mt-2">Diperbarui tiap 30 detik saat Dashboard terbuka. Grafik menyimpan sampai 30 sampel di browser. Router dibaca saja; konfigurasi MikroTik tidak diubah.</div>
    </div>
</section>
@endif

<div class="row g-3 mb-4">
    @foreach([['Total pelanggan',$stats['customers'],'dash-slate'],['Pelanggan aktif',$stats['active'],'dash-green'],['Pelanggan isolir',$stats['isolated'],'dash-red'],['Invoice bulan ini',$stats['invoice_month'],'dash-blue'],['Belum bayar',$stats['unpaid'],'dash-yellow'],['Router online',$stats['routers_online'].' / '.$stats['routers'],'dash-slate']] as $stat)
        <div class="col-sm-6 col-xl-2"><div class="dash-tile {{ $stat[2] }} h-100"><div class="dash-value">{{ $stat[1] }}</div><div class="dash-label">{{ $stat[0] }}</div></div></div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    @foreach($routers as $router)
        @php($resource = $router->meta['resource'] ?? [])
        @php($identity = $router->meta['identity'] ?? $router->name)
        <div class="col-lg-6"><section class="dash-panel h-100"><div class="dash-panel-title">ⓘ {{ $router->name }} · {{ $identity }}</div><div class="p-3 small"><div>Board: <strong>{{ $resource['board-name'] ?? 'Belum tercatat' }}</strong></div><div>RouterOS: <strong>{{ $resource['version'] ?? 'Belum tercatat' }}</strong></div><div>Uptime terakhir tercatat: <strong>{{ $resource['uptime'] ?? '—' }}</strong></div><div>Pemeriksaan koneksi terakhir: <strong>{{ $router->last_seen_at?->timezone(config('billing.timezone'))->format('d/m/Y H:i:s') ?? 'Belum pernah' }}</strong></div><div class="text-muted mt-2">Info sistem memakai catatan pemeriksaan terakhir; hanya grafik WAN yang mengambil sampel live.</div></div></section></div>
    @endforeach
</div>

@if(in_array($billingUser?->role, ['super_admin', 'admin', 'finance'], true))
    <div class="row g-3 mt-1">
        <div class="col-md-4"><div class="dash-panel p-3 h-100"><div class="text-muted">Pendapatan bulan ini</div><div class="fs-4 fw-bold">Rp {{ number_format($stats['paid_month'], 0, ',', '.') }}</div></div></div>
        <div class="col-md-4"><div class="dash-panel p-3 h-100"><div class="text-muted">Tagihan belum lunas</div><div class="fs-4 fw-bold">Rp {{ number_format($stats['unpaid_amount'], 0, ',', '.') }}</div></div></div>
        <div class="col-md-4"><div class="dash-panel p-3 h-100"><div class="text-muted">Tripay hari ini</div><div class="fs-4 fw-bold">Rp {{ number_format($stats['tripay_today'], 0, ',', '.') }}</div></div></div>
    </div>
    <section class="dash-panel mt-4"><div class="dash-panel-title">Pendapatan 6 bulan terakhir</div><div class="p-3">@php($maxRevenue = max(1, $months->max('total')))<div class="d-flex align-items-end gap-3" style="height:180px">@foreach($months as $month)<div class="flex-fill text-center d-flex flex-column justify-content-end h-100"><div class="small text-muted">Rp {{ number_format($month['total'], 0, ',', '.') }}</div><div class="bg-primary rounded-top mx-auto" style="height:{{ max(3, ($month['total'] / $maxRevenue) * 120) }}px;width:min(44px,80%)"></div><div class="small mt-2">{{ $month['label'] }}</div></div>@endforeach</div></div></section>
@endif

@if($canManageNetwork)
<script>
(() => {
    const select = document.getElementById('traffic-router');
    const status = document.getElementById('traffic-status');
    const updated = document.getElementById('traffic-updated');
    const rxText = document.getElementById('traffic-rx');
    const txText = document.getElementById('traffic-tx');
    const rxLine = document.getElementById('traffic-rx-line');
    const txLine = document.getElementById('traffic-tx-line');
    const history = new Map();
    let busy = false;

    const formatRate = (bps) => bps >= 1e9 ? `${(bps / 1e9).toFixed(2)} Gbps` : bps >= 1e6 ? `${(bps / 1e6).toFixed(2)} Mbps` : bps >= 1e3 ? `${(bps / 1e3).toFixed(1)} Kbps` : `${Math.round(bps)} bps`;
    const draw = (samples) => {
        const rx = samples.map((sample) => sample.rx);
        const tx = samples.map((sample) => sample.tx);
        const max = Math.max(1, ...rx, ...tx);
        const x = (index) => 58 + index * (820 / Math.max(1, samples.length - 1));
        const y = (value) => 210 - value / max * 185;
        const path = (values) => values.map((value, index) => `${index ? 'L' : 'M'}${x(index).toFixed(1)} ${y(value).toFixed(1)}`).join(' ');
        rxLine.setAttribute('d', path(rx));
        txLine.setAttribute('d', path(tx));
        document.getElementById('traffic-scale').textContent = formatRate(max);
        rxText.textContent = formatRate(rx.at(-1));
        txText.textContent = formatRate(tx.at(-1));
    };

    const poll = async () => {
        if (busy) return;
        busy = true;
        try {
            const response = await fetch(@json(route('dashboard.traffic')), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            const routers = data.routers || [];
            const previous = select.value;
            select.replaceChildren();
            routers.forEach((router) => {
                const option = document.createElement('option');
                option.value = router.router_id;
                option.textContent = `${router.router} — ${router.interface || 'interface belum diatur'}`;
                select.append(option);
            });
            if (previous && routers.some((router) => String(router.router_id) === previous)) select.value = previous;
            const active = routers.find((router) => String(router.router_id) === select.value) || routers[0];
            if (!active) {
                status.textContent = 'Monitoring WAN belum diaktifkan untuk router ini.';
                updated.textContent = 'Belum ada sampel';
                return;
            }
            if (active.ok) {
                const key = String(active.router_id);
                const samples = [...(history.get(key) || []), {rx: active.rx_bps, tx: active.tx_bps}].slice(-30);
                history.set(key, samples);
                draw(samples);
                updated.textContent = `Diperbarui ${new Date(data.sampled_at).toLocaleTimeString()}`;
                status.textContent = `Interface ${active.interface} · pembacaan saja`;
            } else {
                status.textContent = `Gagal membaca interface ${active.interface || 'WAN'}. Periksa nama interface dan izin API.`;
                updated.textContent = 'Sampel gagal';
            }
        } catch (error) {
            status.textContent = `Gagal memuat trafik: ${error.message}`;
            updated.textContent = 'Sampel gagal';
        } finally {
            busy = false;
        }
    };

    select.addEventListener('change', poll);
    poll();
    window.setInterval(poll, 30000);
})();
</script>
@endif
@endsection

