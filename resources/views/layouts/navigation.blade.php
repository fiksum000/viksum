<div class="nav-section-label">Menu utama</div>
<a class="side-link {{ request()->routeIs('dashboard*') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="side-icon">⌂</span><span>Dashboard</span></a>
@if(in_array($billingUser?->role, ['super_admin','admin','operator','technician'], true))<a class="side-link {{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}"><span class="side-icon">♙</span><span>Pelanggan</span></a>@endif
@if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="side-link {{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}"><span class="side-icon">◈</span><span>Paket internet</span></a>@endif

@if(in_array($billingUser?->role, ['super_admin','admin','technician'], true))
    <div class="nav-section-label mt-4">Jaringan</div>
    <a class="side-link {{ request()->routeIs('routers.*') ? 'active' : '' }}" href="{{ route('routers.index') }}"><span class="side-icon">⌁</span><span>MikroTik</span></a>
    <a class="side-link {{ request()->routeIs('olts.*') ? 'active' : '' }}" href="{{ route('olts.index') }}"><span class="side-icon">▤</span><span>OLT</span></a>
    <a class="side-link {{ request()->routeIs('onus.*') ? 'active' : '' }}" href="{{ route('onus.index') }}"><span class="side-icon">◎</span><span>ONU</span></a>
    <a class="side-link {{ request()->routeIs('hotspot.*') ? 'active' : '' }}" href="{{ route('hotspot.index') }}"><span class="side-icon">◉</span><span>Hotspot</span></a>
@endif

@if(in_array($billingUser?->role, ['super_admin','admin','finance'], true))
    <div class="nav-section-label mt-4">Keuangan</div>
    <a class="side-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}" href="{{ route('invoices.index') }}"><span class="side-icon">▣</span><span>Invoice</span></a>
    <a class="side-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}"><span class="side-icon">▥</span><span>Laporan</span></a>
@endif

@if(in_array($billingUser?->role, ['super_admin','admin'], true))
    <div class="nav-section-label mt-4">Pengaturan</div>
    <a class="side-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><span class="side-icon">♧</span><span>Pengguna</span></a>
    <a class="side-link {{ request()->routeIs('whatsapp.*') ? 'active' : '' }}" href="{{ route('whatsapp.index') }}"><span class="side-icon">◌</span><span>WhatsApp</span></a>
    <a class="side-link {{ request()->routeIs('payment-settings.*') ? 'active' : '' }}" href="{{ route('payment-settings.index') }}"><span class="side-icon">＄</span><span>Pembayaran</span></a>
@endif

