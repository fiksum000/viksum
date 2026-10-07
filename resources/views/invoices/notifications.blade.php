@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="mb-1">Status Pengiriman Invoice</h1><p class="text-muted mb-0">Pantau notifikasi tagihan, pembayaran, dan peringatan isolir, termasuk nomor WhatsApp yang gagal dikirimi.</p></div>
    <a class="btn btn-outline-light" href="{{ route('invoices.index') }}">Kembali ke Invoice</a>
</div>

<div class="row g-3 mb-4">
    @foreach(['queued' => ['Antrean', 'warning'], 'sent' => ['Diterima gateway', 'success'], 'failed' => ['Gagal', 'danger']] as $status => [$label, $color])
        <div class="col-md-4"><div class="card card-body h-100"><div class="text-muted">{{ $label }}</div><div class="fs-3 fw-bold text-{{ $color }}">{{ number_format($counts[$status]) }}</div></div></div>
    @endforeach
</div>

<form method="GET" class="card card-body mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-3"><label for="search" class="form-label">Cari pelanggan / invoice / nomor WA</label><input id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" maxlength="120"></div>
        <div class="col-sm-6 col-lg-2"><label for="period" class="form-label">Periode invoice</label><input id="period" name="period" type="month" value="{{ $filters['period'] ?? '' }}" class="form-control"></div>
        <div class="col-sm-6 col-lg-2"><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-select"><option value="">Semua status</option><option value="queued" @selected(($filters['status'] ?? '') === 'queued')>Dalam antrean</option><option value="sent" @selected(($filters['status'] ?? '') === 'sent')>Diterima gateway</option><option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Gagal</option></select></div>
        <div class="col-sm-6 col-lg-3"><label for="event" class="form-label">Jenis notifikasi</label><select id="event" name="event" class="form-select"><option value="">Semua jenis</option>@foreach(['billing_reminder' => 'Pengingat tagihan', 'isolation_warning' => 'Peringatan H-1 isolir', 'payment_success' => 'Pembayaran lunas', 'isolation' => 'Layanan diisolir'] as $value => $label)<option value="{{ $value }}" @selected(($filters['event'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-sm-6 col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-light" href="{{ route('invoices.notifications') }}">Reset</a></div>
    </div>
</form>

<div class="card card-body">
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Waktu</th><th>Pelanggan</th><th>Invoice</th><th>Tujuan WhatsApp</th><th>Jenis</th><th>Status</th><th>Detail / alasan</th></tr></thead>
        <tbody>
        @forelse($notifications as $notification)
            @php
                $customer = $notification->customer;
                $invoice = $notification->invoice;
                $latestLog = $notification->waLogs->sortByDesc('id')->first();
                $target = $latestLog?->target ?: ($customer?->whatsapp_number ?: $customer?->phone);
                $eventLabels = ['billing_reminder' => 'Pengingat tagihan', 'isolation_warning' => 'Peringatan H-1 isolir', 'payment_success' => 'Pembayaran lunas', 'isolation' => 'Layanan diisolir'];
                $statusLabels = ['queued' => 'Dalam antrean', 'sent' => 'Diterima gateway', 'failed' => 'Gagal'];
                $statusColors = ['queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'];
                $providerDetail = data_get($latestLog?->response, 'detail');
                $reason = $notification->last_error ?: ($notification->status === 'failed' ? $providerDetail : null);
            @endphp
            <tr>
                <td class="text-nowrap">{{ $notification->updated_at->timezone(config('billing.timezone'))->format('d-m-Y H:i') }}</td>
                <td><strong>{{ $customer?->name ?? 'Data pelanggan tidak tersedia' }}</strong><div class="small text-muted">{{ $customer?->customer_code }}</div></td>
                <td>@if($invoice)<a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a><div class="small text-muted">{{ $invoice->period }} · Rp {{ number_format($invoice->total, 0, ',', '.') }}</div>@else—@endif</td>
                <td class="text-nowrap">{{ $target ?: 'Nomor belum diisi' }}</td>
                <td>{{ $eventLabels[$notification->event] ?? str($notification->event)->replace('_', ' ')->title() }}</td>
                <td><span class="badge text-bg-{{ $statusColors[$notification->status] ?? 'secondary' }}">{{ $statusLabels[$notification->status] ?? $notification->status }}</span>@if($latestLog?->provider_status)<div class="small text-muted mt-1">Fonnte: {{ $latestLog->provider_status }}{{ $latestLog->provider_state ? ' · '.$latestLog->provider_state : '' }}</div>@endif</td>
                <td>
                    @if($reason)<span class="text-danger">{{ $reason }}</span>
                    @elseif($notification->status === 'queued')<span class="text-muted">Menunggu worker WhatsApp.</span>
                    @elseif($notification->status === 'sent')<span class="text-success">{{ $providerDetail ?: 'Permintaan diterima Fonnte.' }}</span>
                    @else<span class="text-muted">Belum ada detail dari gateway.</span>@endif
                    @if($latestLog?->message)<details class="small mt-1"><summary class="link-info">Lihat isi pesan</summary><div class="mt-1 text-break">{{ $latestLog->message }}</div></details>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted p-4">Belum ada riwayat notifikasi invoice.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="mt-3">{{ $notifications->links() }}</div>
</div>

<div class="card card-body mt-4">
    <h2 class="h5">Pesan WhatsApp lainnya</h2>
    <p class="text-muted small">Mencakup broadcast dan notifikasi non-invoice. Maksimal 100 percobaan terbaru.</p>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Waktu</th><th>Pelanggan</th><th>Tujuan WhatsApp</th><th>Jenis</th><th>Status gateway</th><th>Detail / isi pesan</th></tr></thead>
        <tbody>
        @forelse($otherLogs as $log)
            @php($logStatusLabels = ['queued' => 'Dalam antrean', 'sent' => 'Diterima gateway', 'failed' => 'Gagal'])
            @php($logStatusColors = ['queued' => 'warning', 'sent' => 'success', 'failed' => 'danger'])
            <tr>
                <td class="text-nowrap">{{ $log->created_at->timezone(config('billing.timezone'))->format('d-m-Y H:i') }}</td>
                <td>{{ $log->customer?->name ?? '—' }}<div class="small text-muted">{{ $log->customer?->customer_code }}</div></td>
                <td class="text-nowrap">{{ $log->target }}</td>
                <td>{{ str($log->event)->replace('_', ' ')->title() }}</td>
                <td><span class="badge text-bg-{{ $logStatusColors[$log->status] ?? 'secondary' }}">{{ $logStatusLabels[$log->status] ?? $log->status }}</span>@if($log->provider_status)<div class="small text-muted mt-1">Fonnte: {{ $log->provider_status }}{{ $log->provider_state ? ' · '.$log->provider_state : '' }}</div>@endif</td>
                <td>@if($log->status === 'failed')<div class="text-danger">{{ data_get($log->response, 'detail', 'Pengiriman gagal; detail gateway tidak tersedia.') }}</div>@endif<details class="small"><summary class="link-info">Lihat isi pesan</summary><div class="mt-1 text-break">{{ $log->message }}</div></details></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted p-3">Belum ada riwayat pesan non-invoice.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
<p class="small text-muted mt-3">Status “Diterima gateway” berarti Fonnte menerima permintaan. Status lanjutan akan diperbarui jika webhook status pesan Fonnte diarahkan ke webhook aplikasi.</p>
@endsection

