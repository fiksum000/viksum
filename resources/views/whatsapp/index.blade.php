@extends('layouts.app')
@section('content')
<h1>WhatsApp Fonnte</h1>
<div class="row g-3 mt-1">
    <div class="col-xl-4">
        <form method="POST" action="{{ route('whatsapp.broadcast') }}" class="card card-body">@csrf
            <h2 class="h5">Broadcast</h2>
            <label class="form-label">Penerima</label><select name="audience" class="form-select mb-3"><option value="all">Semua pelanggan bernomor WA</option><option value="active">Pelanggan aktif</option><option value="isolated">Pelanggan isolir</option><option value="unpaid">Pelanggan dengan invoice belum bayar</option></select>
            <label class="form-label">Pesan</label><textarea name="message" class="form-control" rows="6" maxlength="1000" required></textarea>
            <p class="text-muted small mt-2">Pesan dikirim melalui antrean. Pastikan target sudah benar sebelum menekan kirim.</p>
            <button class="btn btn-primary">Antrekan broadcast</button>
        </form>
    </div>
    <div class="col-xl-8">
        <h2 class="h5">Template pesan</h2>
        @foreach($templates as $template)
            <form method="POST" action="{{ route('whatsapp.templates.update',$template) }}" class="card card-body mb-3">@csrf @method('PUT')
                <div class="row g-2"><div class="col-md-4"><label class="form-label">Nama</label><input name="name" value="{{ $template->name }}" class="form-control" required><div class="text-muted small mt-1">Event: {{ $template->event }}</div></div><div class="col-md-8"><label class="form-label">Isi template</label><textarea name="body" class="form-control" rows="3" required>{{ $template->body }}</textarea><div class="text-muted small mt-1">Placeholder tersedia: &#123;&#123;name&#125;&#125;, &#123;&#123;invoice_number&#125;&#125;, &#123;&#123;amount&#125;&#125;, &#123;&#123;due_date&#125;&#125;, &#123;&#123;payment_url&#125;&#125;, &#123;&#123;message&#125;&#125;</div></div></div>
                <div class="d-flex justify-content-between align-items-center mt-3"><div class="form-check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" class="form-check-input" id="enabled-{{ $template->id }}" @checked($template->enabled)><label class="form-check-label" for="enabled-{{ $template->id }}">Aktif</label></div><button class="btn btn-outline-primary">Simpan template</button></div>
            </form>
        @endforeach
    </div>
</div>
@endsection
