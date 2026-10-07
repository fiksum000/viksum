@extends('layouts.app')
@section('content')
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <div class="text-muted small mb-1">Monitoring jaringan · baca saja</div>
        <h1 class="h3 mb-1">{{ $title }}</h1>
        <div class="text-muted">Data ditarik langsung dari MikroTik. Password dan data rahasia tidak ditampilkan.</div>
    </div>
    @if($selectedRouter)<span class="badge text-bg-success fs-6">{{ $selectedRouter->name }}</span>@endif
</div>

<form method="GET" action="{{ route('network.read', ['page' => $page]) }}" class="card card-body mb-3">
    <label for="router_id" class="form-label">Pilih router untuk membaca data</label>
    <div class="d-flex flex-wrap gap-2">
        <select class="form-select flex-grow-1" style="max-width:32rem" name="router_id" id="router_id" required>
            <option value="">Pilih MikroTik…</option>
            @foreach($routers as $router)
                <option value="{{ $router->id }}" @selected($selectedRouter?->id === $router->id)>{{ $router->name }} — {{ $router->host }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary">Tampilkan data</button>
        @if($selectedRouter)<a class="btn btn-outline-secondary" href="{{ route('network.read', ['page' => $page]) }}">Bersihkan</a>@endif
    </div>
    <div class="form-text">Tidak ada koneksi ke router sebelum kamu memilih router dan menekan tombol.</div>
</form>

@if($error)
    <div class="alert alert-warning">{{ $error }}</div>
@elseif($selectedRouter)
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">{{ count($rows) }} data</span>
        <a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrl() }}">Muat ulang</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>@foreach($columns as $column)<th>{{ str($column)->replace('-', ' ')->title() }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>@foreach($row as $value)<td>{{ is_scalar($value) ? $value : json_encode($value) }}</td>@endforeach</tr>
            @empty
                <tr><td class="text-center text-muted p-4" colspan="12">Tidak ada data pada router ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@else
    <div class="card card-body text-center text-muted py-5">Pilih router di atas untuk melihat {{ strtolower($title) }}.</div>
@endif
@endsection

