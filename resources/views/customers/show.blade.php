@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <a href="{{ route('customers.index') }}" class="text-decoration-none small">&larr; Kembali ke pelanggan</a>
        <h1 class="mb-0 mt-1">Detail Pelanggan</h1>
        <div class="text-muted small">{{ $customer->customer_code }}</div>
    </div>
    <div class="d-flex gap-2">
        @if(in_array($billingUser?->role,['super_admin','admin','operator'],true))
            <a href="{{ route('customers.edit',$customer) }}" class="btn btn-primary">Edit pelanggan</a>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin','operator'],true) && $customer->status === 'active')
            <form method="POST" action="{{ route('customers.isolate',$customer) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Isolir pelanggan {{ $customer->name }} sekarang? Sesi aktif akan diputus dan metode isolir sesuai pengaturan billing diterapkan.">
                @csrf
                <button class="btn btn-outline-danger" type="submit">Isolir</button>
            </form>
        @elseif(in_array($billingUser?->role,['super_admin','admin','operator'],true) && $customer->status === 'isolated')
            <form method="POST" action="{{ route('customers.unisolate',$customer) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Buka isolir pelanggan {{ $customer->name }}? Profil layanan normal atau profil FUP yang sesuai akan dipulihkan.">
                @csrf
                <button class="btn btn-outline-success" type="submit">Buka isolir</button>
            </form>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin','operator'],true) && $customer->status !== 'terminated')
            <form method="POST" action="{{ route('customers.terminate',$customer) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Hentikan layanan {{ $customer->name }}? Akun layanan akan dinonaktifkan dan sesi aktif diputus, tetapi data serta riwayat tagihan tetap disimpan.">
                @csrf
                @method('PATCH')
                <button class="btn btn-outline-warning" type="submit">Hentikan layanan</button>
            </form>
        @endif
        @if(in_array($billingUser?->role,['super_admin','admin'],true))
            <form method="POST" action="{{ route('customers.destroy',$customer) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Hapus permanen pelanggan {{ $customer->customer_code }} — {{ $customer->name }}? Jika memiliki riwayat tagihan, penghapusan akan ditolak.">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger" type="submit">Hapus</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <section class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Informasi pelanggan</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Nama</dt><dd class="col-sm-8">{{ $customer->name }}</dd>
                    <dt class="col-sm-4">Status layanan</dt><dd class="col-sm-8"><span class="badge text-bg-{{ $customer->status === 'active' ? 'success' : ($customer->status === 'isolated' ? 'danger' : 'secondary') }}">{{ ucfirst($customer->status) }}</span></dd>
                    <dt class="col-sm-4">Jenis layanan</dt><dd class="col-sm-8">{{ strtoupper($customer->service_type) }}</dd>
                    <dt class="col-sm-4">WhatsApp</dt><dd class="col-sm-8">{{ $customer->whatsapp_number ?: ($customer->phone ?: '—') }}</dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8">{{ $customer->email ?: '—' }}</dd>
                    <dt class="col-sm-4">Alamat</dt><dd class="col-sm-8">{{ collect([$customer->address, $customer->rt ? 'RT '.$customer->rt : null, $customer->rw ? 'RW '.$customer->rw : null, $customer->village, $customer->district, $customer->city])->filter()->implode(', ') ?: '—' }}</dd>
                    <dt class="col-sm-4">Area</dt><dd class="col-sm-8">{{ $customer->area ?: '—' }}</dd>
                    <dt class="col-sm-4">Paket</dt><dd class="col-sm-8">{{ $customer->package?->name ?: '—' }}</dd>
                    <dt class="col-sm-4">Router</dt><dd class="col-sm-8">{{ $customer->router?->name ?: '—' }}</dd>
                    <dt class="col-sm-4">Username layanan</dt><dd class="col-sm-8">{{ $customer->service_type === 'pppoe' ? ($customer->pppoe_username ?: '—') : ($customer->hotspot_username ?: '—') }}</dd>
                    <dt class="col-sm-4">Tanggal terdaftar</dt><dd class="col-sm-8">{{ $customer->registered_at?->format('d-m-Y') ?: '—' }}</dd>
                    <dt class="col-sm-4">Jatuh tempo</dt><dd class="col-sm-8">Tanggal {{ $customer->due_day }} setiap bulan</dd>
                    <dt class="col-sm-4">Catatan</dt><dd class="col-sm-8 text-break">{{ $customer->notes ?: '—' }}</dd>
                </dl>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="card shadow-sm mb-3">
            <div class="card-header fw-semibold">Koneksi & konfigurasi</div>
            <div class="card-body">
                <dl class="row mb-0">
                    @if($customer->service_type === 'pppoe')
                        <dt class="col-5">IP PPPoE</dt><dd class="col-7">{{ $customer->pppoe_ip ?: 'Otomatis' }}</dd>
                        <dt class="col-5">Profil normal</dt><dd class="col-7">{{ $customer->pppoe_profile_normal ?: $customer->package?->normal_profile ?: '—' }}</dd>
                        <dt class="col-5">Profil isolir</dt><dd class="col-7">{{ $customer->pppoe_profile_isolir ?: config('billing.isolation_profile', '—') }}</dd>
                        <dt class="col-5">FUP</dt><dd class="col-7">{{ $customer->fup_override === null ? 'Ikuti paket' : ($customer->fup_override ? 'Aktif' : 'Nonaktif') }}</dd>
                        <dt class="col-5">Pemakaian FUP</dt><dd class="col-7">{{ $fupState ? number_format($fupState->total_bytes / 1073741824, 2, ',', '.').' GB' : 'Belum ada data periode ini' }}</dd>
                        <dt class="col-5">Status batas FUP</dt><dd class="col-7">{{ $fupState?->limited ? 'Kecepatan dibatasi' : 'Normal / belum dibatasi' }}</dd>
                    @else
                        <dt class="col-5">Profil Hotspot</dt><dd class="col-7">{{ $customer->hotspotProfile?->name ?: $customer->hotspot_profile ?: '—' }}</dd>
                    @endif
                    <dt class="col-5">OLT</dt><dd class="col-7">{{ $customer->onu?->olt?->name ?: $customer->olt_name ?: '—' }}</dd>
                    <dt class="col-5">Port PON</dt><dd class="col-7">{{ $customer->onu?->pon_port ?: $customer->pon_port ?: '—' }}</dd>
                    <dt class="col-5">Serial ONU</dt><dd class="col-7">{{ $customer->onu?->serial_number ?: $customer->onu_sn ?: '—' }}</dd>
                </dl>
                <p class="small text-muted mb-0 mt-2">Password layanan tidak ditampilkan di halaman detail.</p>
            </div>
        </section>
        <section class="card shadow-sm">
            <div class="card-header fw-semibold">Riwayat tagihan terbaru</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Periode</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($customer->invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->period }}</td>
                            <td>Rp {{ number_format($invoice->total,0,',','.') }}</td>
                            <td><span class="badge text-bg-{{ $invoice->status === 'paid' ? 'success' : ($invoice->status === 'unpaid' ? 'warning' : 'secondary') }}">{{ ucfirst($invoice->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">Belum ada tagihan.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
@endsection
