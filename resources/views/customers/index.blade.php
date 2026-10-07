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
                <input type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Import XLSX atau CSV">
            </form>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin','operator'],true))
            <a class="btn btn-primary" href="{{route('customers.create')}}">+ Tambah</a>
        @endif
    </div>
</div>
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
    <span class="text-muted">Koneksi dibaca saat halaman dibuka; status billing dan koneksi router ditampilkan terpisah.</span>
</div>
<div class="table-responsive shadow-sm">
    <table class="table table-sm table-hover mb-0">
        <thead><tr><th>ID</th><th>Nama</th><th>WhatsApp</th><th>Paket</th><th>Layanan</th><th>Router</th><th>Status layanan</th><th>Tagihan {{ $billingPeriod }}</th><th>Koneksi MikroTik</th><th></th></tr></thead>
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
                <td>{{$c->customer_code}}</td>
                <td>{{$c->name}}</td>
                <td>{{$c->whatsapp_number?:$c->phone}}</td>
                <td>{{$c->package?->name}}</td>
                <td><span class="badge text-bg-secondary">{{strtoupper($c->service_type)}}</span><div class="small text-muted">{{$username?:'Username belum diatur'}}</div></td>
                <td>{{$c->router?->name??'—'}}</td>
                <td><span class="badge text-bg-{{$serviceStatus[1]}}">{{$serviceStatus[0]}}</span></td>
                <td>
                    <span class="badge rounded-pill" style="{{$billingStatus[1]}}">{{$billingStatus[0]}}</span>
                    @if($currentInvoice)<div class="small text-muted mt-1">Jatuh tempo {{$currentInvoice->due_date?->format('d-m-Y')}}</div>@endif
                </td>
                <td>
                    <span class="badge rounded-pill" style="{{$connectionStatus[1]}}">{{$connectionStatus[0]}}</span>
                    @if($c->live_connection_status==='unknown')<div class="small text-muted mt-1">Router tidak dapat dibaca</div>@endif
                </td>
                <td>@if(in_array($billingUser?->role,['super_admin','admin','operator'],true))<a href="{{route('customers.edit',$c)}}" class="btn btn-sm btn-outline-secondary">Edit</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="10" class="text-center p-4">Belum ada pelanggan.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{$customers->links()}}</div>
@endsection
