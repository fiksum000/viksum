@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="mb-0">Invoice & Arsip</h1>
    <a href="{{ route('invoices.notifications') }}" class="btn btn-outline-info">Status Pengiriman WA</a>
    @if(in_array($billingUser?->role, ['super_admin', 'admin'], true))
        <form method="POST" action="{{ route('invoices.generate') }}" class="d-flex gap-2">
            @csrf
            <input name="period" type="month" value="{{ now(config('billing.timezone'))->format('Y-m') }}" class="form-control" required>
            <button class="btn btn-primary">Generate Invoice</button>
        </form>
    @endif
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('invoices.index') }}" class="row g-2 align-items-end">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="period">Periode</label>
                <input id="period" name="period" type="month" value="{{ $filters['period'] ?? '' }}" class="form-control">
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="service_type">Jenis layanan</label>
                <select id="service_type" name="service_type" class="form-select">
                    <option value="">Semua layanan</option>
                    <option value="pppoe" @selected(($filters['service_type'] ?? '') === 'pppoe')>PPPoE</option>
                    <option value="hotspot" @selected(($filters['service_type'] ?? '') === 'hotspot')>Hotspot</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="status">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Semua status</option>
                    @foreach(['unpaid' => 'Belum lunas', 'paid' => 'Lunas', 'draft' => 'Draft', 'cancelled' => 'Dibatalkan', 'expired' => 'Kedaluwarsa'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1">Tampilkan</button>
                <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
        <form method="GET" action="{{ route('invoices.archive') }}" class="row g-2 align-items-end border-top mt-3 pt-3">
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="archive-period">Unduh arsip bulan</label>
                <input id="archive-period" name="period" type="month" value="{{ $archivePeriod }}" class="form-control" required>
            </div>
            <div class="col-sm-4 col-lg-3">
                <label class="form-label" for="archive-service">Jenis layanan</label>
                <select id="archive-service" name="service_type" class="form-select">
                    <option value="">Semua layanan</option>
                    <option value="pppoe" @selected(($filters['service_type'] ?? '') === 'pppoe')>PPPoE</option>
                    <option value="hotspot" @selected(($filters['service_type'] ?? '') === 'hotspot')>Hotspot</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <button class="btn btn-success">Unduh arsip PDF (.ZIP)</button>
            </div>
            <div class="col-12 form-text">Arsip berisi satu PDF untuk setiap invoice pada bulan dan jenis layanan yang dipilih. Bisa dicetak dan disimpan sebagai arsip.</div>
        </form>
    </div>
</div>

<div class="table-responsive shadow-sm">
    <table class="table table-hover align-middle">
        <thead><tr><th>Invoice</th><th>Pelanggan</th><th>Layanan</th><th>Periode</th><th>Jatuh tempo</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
            @forelse($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->invoice_number }}</td>
                    <td>{{ $invoice->customer->name }}</td>
                    <td>{{ strtoupper($invoice->customer->service_type) }}</td>
                    <td>{{ $invoice->period }}</td>
                    <td>{{ $invoice->due_date->format('d-m-Y') }}</td>
                    <td>Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
                    <td><span class="badge text-bg-{{ $invoice->status === 'paid' ? 'success' : 'warning' }}">{{ $invoice->status }}</span></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show', $invoice) }}">Detail</a>
                        <a class="btn btn-sm btn-outline-success" href="{{ route('invoices.pdf.download', $invoice) }}">PDF</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center p-4">Belum ada invoice untuk filter ini.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $invoices->links() }}</div>
@endsection

