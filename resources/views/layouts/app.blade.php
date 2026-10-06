<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#000000">
    <title>{{ $title ?? 'Billing RTRW Net' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { color-scheme: dark; }
        html, body { min-height: 100%; background: #000 !important; color: #f8f9fa; }
        .navbar { border-bottom: 1px solid #292929; }
        .card, .table-responsive, .bg-white { background-color: #101010 !important; color: #f8f9fa; border-color: #2b2b2b; }
        .card { --bs-card-bg: #101010; --bs-card-color: #f8f9fa; --bs-card-border-color: #2b2b2b; }
        .table { --bs-table-color: #f8f9fa; --bs-table-bg: #101010; --bs-table-border-color: #303030; --bs-table-striped-color: #f8f9fa; --bs-table-striped-bg: #171717; --bs-table-hover-color: #fff; --bs-table-hover-bg: #202020; }
        .form-control, .form-select { background-color: #111; color: #f8f9fa; border-color: #444; }
        .form-control:focus, .form-select:focus { background-color: #151515; color: #fff; border-color: #6ea8fe; }
        .form-control::placeholder { color: #9b9b9b; }
        .form-select option { background: #111; color: #fff; }
        .text-muted { color: #adb5bd !important; }
        .form-text { color: #adb5bd !important; }
        .alert { --bs-alert-color: #f8f9fa; --bs-alert-bg: #101010; --bs-alert-border-color: #343434; }
        .alert-secondary { --bs-alert-color: #e9ecef; --bs-alert-bg: #151515; --bs-alert-border-color: #343434; }
        .alert-success { --bs-alert-color: #b8f3c7; --bs-alert-bg: #102318; --bs-alert-border-color: #245c37; }
        .alert-danger { --bs-alert-color: #ffc2c7; --bs-alert-bg: #2a1114; --bs-alert-border-color: #71343a; }
        .alert-warning { --bs-alert-color: #ffe69c; --bs-alert-bg: #2b230d; --bs-alert-border-color: #66551f; }
        .alert-info { --bs-alert-color: #b6e3f5; --bs-alert-bg: #10232c; --bs-alert-border-color: #28546a; }
        .dropdown-menu { --bs-dropdown-bg: #111; --bs-dropdown-color: #f8f9fa; --bs-dropdown-link-color: #eee; --bs-dropdown-link-hover-bg: #222; }
        .modal-content, .list-group-item { background: #101010; color: #f8f9fa; border-color: #303030; }
        .page-link { background: #101010; color: #d5d5d5; border-color: #303030; }
        .page-link:hover, .page-item.active .page-link { background: #222; color: #fff; border-color: #555; }
        .table-responsive { border-radius: .5rem; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-dark navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="{{ route('dashboard') }}">Billing RTRW Net</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Buka navigasi"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <div class="navbar-nav">
                <a class="nav-link" href="{{ route('dashboard') }}">Dashboard</a>
                @if(in_array($billingUser?->role, ['super_admin','admin','operator','technician'], true))<a class="nav-link" href="{{ route('customers.index') }}">Pelanggan</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="nav-link" href="{{ route('packages.index') }}">Paket</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin','technician'], true))<a class="nav-link" href="{{ route('routers.index') }}">MikroTik</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin','technician'], true))<a class="nav-link" href="{{ route('olts.index') }}">OLT</a><a class="nav-link" href="{{ route('onus.index') }}">ONU</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin','technician'], true))<a class="nav-link" href="{{ route('hotspot.index') }}">Hotspot</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin','finance'], true))<a class="nav-link" href="{{ route('invoices.index') }}">Invoice</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin','finance'], true))<a class="nav-link" href="{{ route('reports.index') }}">Laporan</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="nav-link" href="{{ route('users.index') }}">Pengguna</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="nav-link" href="{{ route('whatsapp.index') }}">WhatsApp</a>@endif
                @if(in_array($billingUser?->role, ['super_admin','admin'], true))<a class="nav-link" href="{{ route('payment-settings.index') }}">Pembayaran</a>@endif
            </div>
            <div class="ms-auto text-white d-flex align-items-center gap-2"><span>{{ $billingUser?->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-outline-light">Keluar</button></form></div>
        </div>
    </div>
</nav>
<main class="container-fluid py-4">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
