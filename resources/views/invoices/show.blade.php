@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
        <h1>{{ $invoice->invoice_number }}</h1>
        <p>{{ $invoice->customer->name }} — {{ $invoice->period }}</p>
    </div>
    <div>
        @if ($activePayment)
            <a class="btn btn-outline-primary" href="{{ $activePayment->checkout_url }}" target="_blank" rel="noopener">Lanjutkan checkout {{ $activePayment->channel }}</a>
        @elseif ($invoice->status === 'unpaid' && $paymentChannels)
            <form method="POST" action="{{ route('invoices.pay', $invoice) }}" class="d-flex gap-2">
                @csrf
                <select name="method" class="form-select" required>
                    @foreach ($paymentChannels as $channel)
                        <option value="{{ $channel['code'] }}">{{ $channel['name'] }}</option>
                    @endforeach
                </select>
                <button class="btn btn-success">Buat Pembayaran</button>
            </form>
        @elseif ($invoice->status === 'paid')
            <span class="badge text-bg-success fs-6">LUNAS</span>
        @endif
        @if (in_array($billingUser?->role, ['super_admin', 'admin'], true) && in_array($invoice->status, ['draft', 'unpaid'], true) && $invoice->payments->isEmpty())
            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Batalkan invoice {{ $invoice->invoice_number }}? Tindakan ini tidak menghapus riwayat pelanggan.">
                @csrf
                <button class="btn btn-outline-danger mt-2" type="submit">Batalkan invoice</button>
            </form>
        @endif
    </div>
</div>

<section class="card card-body mt-3">
    <p>Link pelanggan: <a href="{{ route('public.pay', $invoice->public_token) }}" target="_blank" rel="noopener">{{ route('public.pay', $invoice->public_token) }}</a></p>
    <div class="row">
        <div class="col-md-6">Paket: <strong>{{ $invoice->customer->package?->name }}</strong><br>Jatuh tempo: <strong>{{ $invoice->due_date->format('d-m-Y') }}</strong></div>
        <div class="col-md-6 text-md-end">Status: <strong>{{ strtoupper($invoice->status) }}</strong></div>
    </div>
    <div class="table-responsive mt-3">
        <table class="table table-sm mb-0">
            <thead><tr><th>Rincian</th><th class="text-end">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr><td>{{ $item->description }} ({{ $item->quantity }} × Rp {{ number_format($item->unit_price, 0, ',', '.') }})</td><td class="text-end">Rp {{ number_format($item->line_total, 0, ',', '.') }}</td></tr>
                @endforeach
                <tr><td>Subtotal</td><td class="text-end">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
                @if ($invoice->discount)<tr><td>Diskon</td><td class="text-end">− Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td></tr>@endif
                @if ($invoice->penalty)<tr><td>Denda</td><td class="text-end">Rp {{ number_format($invoice->penalty, 0, ',', '.') }}</td></tr>@endif
                @if ($invoice->tax_amount)<tr><td>Pajak ({{ $invoice->tax_rate }}%)</td><td class="text-end">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>@endif
            </tbody>
            <tfoot><tr><th>Total tagihan</th><th class="text-end fs-5">Rp {{ number_format($invoice->total, 0, ',', '.') }}</th></tr></tfoot>
        </table>
    </div>
    @if ($invoice->payment_url)
        <hr><a href="{{ $invoice->payment_url }}" target="_blank" rel="noopener" class="btn btn-outline-primary align-self-start">Buka Halaman Pembayaran</a>
    @endif
    @if ($invoice->status === 'unpaid')
        <hr>
        <form method="POST" action="{{ route('invoices.remind', $invoice) }}" class="align-self-start">
            @csrf
            <button class="btn btn-outline-success">Kirim pengingat tagihan ke pelanggan</button>
            <div class="form-text">Maksimal satu pengingat manual per invoice per hari.</div>
        </form>
    @endif
</section>

@if ($invoice->status === 'unpaid')
    <section class="card mt-3">
        <div class="card-body">
            <h2 class="h5">Pembayaran Manual</h2>
            @if ($invoice->payments->contains('provider', 'tripay') || filled($invoice->payment_reference) || filled($invoice->payment_url))
                <div class="alert alert-warning mb-0">Invoice memiliki checkout atau riwayat Tripay. Periksa status transaksi gateway terlebih dahulu agar pembayaran manual tidak menyebabkan tagihan terbayar dua kali.</div>
            @else
            <form method="POST" action="{{ route('invoices.manual-payment', $invoice) }}" class="row g-2">
                @csrf
                <div class="col-md-4"><label class="form-label" for="amount">Nominal</label><input id="amount" type="number" name="amount" class="form-control" value="{{ $invoice->total }}" min="1" required></div>
                <div class="col-md-4"><label class="form-label" for="channel">Kanal pembayaran</label><input id="channel" name="channel" class="form-control" value="Cash" maxlength="50" required></div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-warning w-100">Catat Lunas Manual</button></div>
            </form>
            @endif
        </div>
    </section>

    <section class="card mt-3">
        <div class="card-body">
            <h2 class="h5">Penyesuaian Invoice</h2>
            @if ($invoice->payments->contains('provider', 'tripay'))
                <div class="text-muted">Penyesuaian dikunci setelah checkout Tripay dibuat untuk mencegah nominal transaksi berbeda dengan invoice.</div>
            @else
                <form method="POST" action="{{ route('invoices.adjust', $invoice) }}" class="row g-2">
                    @csrf
                    @method('PUT')
                    <div class="col-md-4"><label class="form-label" for="discount">Diskon (Rp)</label><input id="discount" name="discount" type="number" min="0" max="{{ $invoice->subtotal }}" value="{{ $invoice->discount }}" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label" for="penalty">Denda (Rp)</label><input id="penalty" name="penalty" type="number" min="0" value="{{ $invoice->penalty }}" class="form-control" required></div>
                    <div class="col-md-4 d-flex align-items-end"><button class="btn btn-outline-warning w-100">Simpan Penyesuaian</button></div>
                    <div class="form-text">Total dihitung ulang dari subtotal − diskon + pajak + denda.</div>
                </form>
            @endif
        </div>
    </section>
@endif

<section class="card mt-3">
    <div class="card-body">
        <h2 class="h5">Riwayat Pembayaran</h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Reference</th><th>Channel</th><th>Status</th><th>Waktu</th></tr></thead>
                <tbody>
                    @forelse ($invoice->payments as $payment)
                        <tr><td>{{ $payment->reference ?? '—' }}</td><td>{{ $payment->channel }}</td><td>{{ $payment->status }}</td><td>{{ $payment->paid_at }}</td></tr>
                    @empty
                        <tr><td colspan="4">Belum ada transaksi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

