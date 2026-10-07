@extends('layouts.app')

@section('content')
<h1>Hotspot Voucher</h1>
<div class="row g-3 mb-4">
    @if($canManageVouchers)
        <div class="col-lg-5">
            <form method="POST" action="{{ route('hotspot.generate') }}" class="card card-body">
                @csrf
                <h2 class="h5">Buat voucher di MikroTik</h2>
                <label class="form-label">Router</label>
                <select name="router_id" class="form-select mb-2" required>@foreach($routers as $router)<option value="{{ $router->id }}">{{ $router->name }}</option>@endforeach</select>
                <label class="form-label">Hotspot profile</label><input name="profile" class="form-control mb-2" placeholder="default" required>
                <label class="form-label">Jumlah (maksimal 200 sekali proses)</label><input name="quantity" type="number" min="1" max="200" value="10" class="form-control mb-2" required>
                <label class="form-label">Awalan username</label><input name="prefix" value="WIFI" class="form-control" maxlength="12">
                <button class="btn btn-primary mt-3">Buat dan simpan voucher</button>
            </form>
        </div>
    @endif
    <div class="col-lg-{{ $canManageVouchers ? 7 : 12 }}">
        <div class="card card-body h-100">
            <h2 class="h5">Monitoring aktif</h2>
            <p class="text-muted">Baca sesi aktif langsung dari MikroTik RouterOS.</p>
            @foreach($routers as $router)
                <a class="btn btn-outline-light mb-2" href="{{ route('hotspot.active', $router) }}" target="_blank" rel="noopener">Lihat user aktif — {{ $router->name }}</a>
            @endforeach
        </div>
    </div>
</div>

@if($canManageVouchers)
    <form id="print-vouchers" method="GET" action="{{ route('hotspot.print') }}"></form>
@endif
<div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h5 mb-0">Daftar voucher</h2>
    @if($canManageVouchers)<button form="print-vouchers" class="btn btn-outline-primary btn-sm">Cetak voucher terpilih ke PDF</button>@endif
</div>
<div class="table-responsive rounded">
    <table class="table table-sm align-middle">
        <thead><tr>@if($canManageVouchers)<th></th>@endif<th>Username</th>@if($canManageVouchers)<th>Password</th>@endif<th>Router</th><th>Profile</th><th>Status</th>@if($canManageVouchers)<th>Aksi</th>@endif</tr></thead>
        <tbody>
            @forelse($vouchers as $voucher)
                <tr>
                    @if($canManageVouchers)<td><input type="checkbox" name="ids[]" form="print-vouchers" value="{{ $voucher->id }}" aria-label="Pilih {{ $voucher->username }}"></td>@endif
                    <td>{{ $voucher->username }}</td>
                    @if($canManageVouchers)<td><code>{{ $voucher->password }}</code></td>@endif
                    <td>{{ $voucher->router->name }}</td><td>{{ $voucher->profile }}</td><td>{{ $voucher->status }}</td>
                    @if($canManageVouchers)
                        <td><div class="d-flex gap-1">
                            <form method="POST" action="{{ route('hotspot.toggle', $voucher) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-warning">{{ $voucher->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>
                            <form method="POST" action="{{ route('hotspot.destroy', $voucher) }}" onsubmit="return confirm('Hapus dari MikroTik?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
                        </div></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canManageVouchers ? 7 : 4 }}" class="text-center p-4">Belum ada voucher.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $vouchers->links() }}
@endsection
