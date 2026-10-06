<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#000000">
    <title>Bayar {{ $invoice->invoice_number }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { color-scheme: dark; }
        html, body { min-height: 100%; background: #000 !important; color: #f8f9fa; }
        .card { --bs-card-bg: #101010; --bs-card-color: #f8f9fa; border-color: #2b2b2b; }
        .form-select { background: #111; color: #fff; border-color: #444; }
    </style>
</head>
<body>
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <section class="card shadow">
                <div class="card-body p-4">
                    <h1 class="h3">Pembayaran Internet</h1>
                    <p class="text-muted">{{ $invoice->invoice_number }} · {{ $invoice->customer->name }}</p>
                    <p class="display-6 mb-3">Rp {{ number_format($invoice->total, 0, ',', '.') }}</p>
                    @if ($invoice->status === 'paid')
                        <div class="alert alert-success">Invoice sudah lunas.</div>
                    @else
                        @if (session('error'))
                            <div class="alert alert-danger">{{ session('error') }}</div>
                        @endif
                        @if ($activePayment)
                            <div class="alert alert-info">Checkout pembayaran masih aktif untuk metode {{ $activePayment->channel }}.</div>
                            <a class="btn btn-primary w-100" href="{{ $activePayment->checkout_url }}" rel="noopener">Lanjutkan Pembayaran</a>
                            <p class="small text-muted mt-3 mb-0">Berlaku sampai {{ $invoice->payment_expired_at->timezone(config('billing.timezone'))->format('d-m-Y H:i') }}.</p>
                        @else
                            <form method="POST" action="{{ url('/pay/'.$invoice->public_token) }}">
                                @csrf
                                <label class="form-label" for="method">Metode pembayaran</label>
                                <select id="method" name="method" class="form-select mb-3" required>
                                    @forelse ($paymentChannels as $channel)
                                        <option value="{{ $channel['code'] }}">{{ $channel['name'] }}</option>
                                    @empty
                                        <option value="">Channel pembayaran belum tersedia</option>
                                    @endforelse
                                </select>
                                <button class="btn btn-primary w-100" @disabled(empty($paymentChannels))>Bayar Sekarang</button>
                            </form>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </div>
</main>
</body>
</html>
