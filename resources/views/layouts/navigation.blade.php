<div class="nav-section-label">Menu utama</div>
<a class="side-link {{ request()->routeIs('dashboard*') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="side-icon">⌂</span><span>Dashboard</span></a>

@if(in_array($billingUser?->role, ['super_admin','admin','operator','technician'], true))
    <div class="nav-section-label mt-4">Billing</div>
    <a class="side-link {{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}"><span class="side-icon">♙</span><span>Pelanggan</span></a>
    @if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="side-link {{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}"><span class="side-icon">◈</span><span>Paket internet</span></a>@endif
@endif

@if(in_array($billingUser?->role, ['super_admin','admin','technician'], true))
    <div class="nav-section-label mt-4">Jaringan</div>
    <a class="side-link {{ request()->routeIs('routers.*') ? 'active' : '' }}" href="{{ route('routers.index') }}"><span class="side-icon">⌁</span><span>MikroTik</span></a>
    <details class="side-group" {{ (request()->routeIs('network.read') && str_starts_with(request()->route('page') ?? '', 'hotspot.')) || request()->routeIs('hotspot.index') ? 'open' : '' }}>
        <summary class="side-link {{ (request()->routeIs('network.read') && str_starts_with(request()->route('page') ?? '', 'hotspot.')) || request()->routeIs('hotspot.index') ? 'active' : '' }}"><span class="side-icon">◉</span><span>Hotspot</span></summary>
        <div class="side-submenu">
            <a class="side-link {{ request()->route('page') === 'hotspot.users' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'hotspot.users']) }}">Hotspot Users</a>
            <a class="side-link {{ request()->route('page') === 'hotspot.active-users' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'hotspot.active-users']) }}">Hotspot Active</a>
            <a class="side-link {{ request()->route('page') === 'hotspot.profiles' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'hotspot.profiles']) }}">Hotspot Profiles</a>
            <a class="side-link {{ request()->routeIs('hotspot.index') ? 'active' : '' }}" href="{{ route('hotspot.index') }}">Voucher & Quick Print</a>
        </div>
    </details>
    <details class="side-group" {{ request()->routeIs('network.read') && str_starts_with(request()->route('page') ?? '', 'pppoe.') ? 'open' : '' }}>
        <summary class="side-link {{ request()->routeIs('network.read') && str_starts_with(request()->route('page') ?? '', 'pppoe.') ? 'active' : '' }}"><span class="side-icon">⌁</span><span>PPPoE</span></summary>
        <div class="side-submenu">
            <a class="side-link {{ request()->route('page') === 'pppoe.secrets' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'pppoe.secrets']) }}">PPP Secrets</a>
            <a class="side-link {{ request()->route('page') === 'pppoe.active' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'pppoe.active']) }}">PPP Active</a>
            <a class="side-link {{ request()->route('page') === 'pppoe.offline' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'pppoe.offline']) }}">PPP Offline</a>
            <a class="side-link {{ request()->route('page') === 'pppoe.profiles' ? 'active' : '' }}" href="{{ route('network.read', ['page' => 'pppoe.profiles']) }}">PPP Profiles</a>
        </div>
    </details>
    <a class="side-link {{ request()->routeIs('olts.*') ? 'active' : '' }}" href="{{ route('olts.index') }}"><span class="side-icon">▤</span><span>OLT</span></a>
    <a class="side-link {{ request()->routeIs('onus.*') ? 'active' : '' }}" href="{{ route('onus.index') }}"><span class="side-icon">◎</span><span>ONU</span></a>
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

