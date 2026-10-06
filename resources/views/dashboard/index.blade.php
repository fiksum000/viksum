@extends('layouts.app')
@section('content')
<h1 class="mb-4">Dashboard</h1>
<div class="row g-3">
    @foreach([['Total pelanggan',$stats['customers']],['Pelanggan aktif',$stats['active']],['Pelanggan isolir',$stats['isolated']],['Invoice bulan ini',$stats['invoice_month']],['Belum bayar',$stats['unpaid']],['Router online',$stats['routers_online'].' / '.$stats['routers']]] as $stat)
        <div class="col-sm-6 col-xl-2"><div class="card h-100"><div class="card-body"><div class="text-muted">{{ $stat[0] }}</div><div class="fs-4 fw-bold">{{ $stat[1] }}</div></div></div></div>
    @endforeach
</div>
@if(in_array($billingUser?->role,['super_admin','admin','finance'],true))
    <div class="row g-3 mt-1"><div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted">Pendapatan bulan ini</div><div class="fs-4 fw-bold">Rp {{number_format($stats['paid_month'],0,',','.')}}</div></div></div></div><div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted">Tagihan belum lunas</div><div class="fs-4 fw-bold">Rp {{number_format($stats['unpaid_amount'],0,',','.')}}</div></div></div></div><div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-muted">Tripay hari ini</div><div class="fs-4 fw-bold">Rp {{number_format($stats['tripay_today'],0,',','.')}}</div></div></div></div></div>
    <div class="card mt-4"><div class="card-body"><h2 class="h5">Pendapatan 6 bulan terakhir</h2>@php($maxRevenue=max(1,$months->max('total')))<div class="d-flex align-items-end gap-3" style="height:180px">@foreach($months as $month)<div class="flex-fill text-center d-flex flex-column justify-content-end h-100"><div class="small text-muted">Rp {{number_format($month['total'],0,',','.')}}</div><div class="bg-primary rounded-top mx-auto" style="height:{{max(3,($month['total']/$maxRevenue)*120)}}px;width:min(44px,80%)"></div><div class="small mt-2">{{$month['label']}}</div></div>@endforeach</div></div></div>
@endif
<div class="card mt-4"><div class="card-body"><h2 class="h5">Alur layanan</h2><p class="mb-0">Pelanggan → Invoice → Pembayaran → Aktivasi layanan → Notifikasi WhatsApp.</p></div></div>
@endsection
