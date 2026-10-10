@extends('layouts.app')
@section('content')
<h1>Inventaris dan Monitoring ONU</h1>
<p class="text-muted">Nilai telemetri dapat dicatat di sini; pembacaan otomatis dari OLT memerlukan adapter yang cocok dengan vendor/model.</p>
@if(in_array($billingUser?->role,['super_admin','admin'],true))<form method="POST" action="{{route('onus.store')}}" class="card card-body mb-4">@csrf<h2 class="h5">Tambah / catat ONU</h2><div class="row g-2"><div class="col-md-3"><select name="olt_id" class="form-select" required><option value="">Pilih OLT</option>@foreach($olts as $olt)<option value="{{$olt->id}}">{{$olt->name}}</option>@endforeach</select></div><div class="col-md-3"><select name="customer_id" class="form-select"><option value="">Belum terpasang ke pelanggan</option>@foreach($customers as $customer)<option value="{{$customer->id}}">{{$customer->customer_code}} — {{$customer->name}}</option>@endforeach</select></div><div class="col-md-2"><input name="name" class="form-control" placeholder="Nama ONU"></div><div class="col-md-2"><input name="pon_port" class="form-control" placeholder="PON port" required></div><div class="col-md-2"><input name="onu_id" class="form-control" placeholder="ONU ID" required></div><div class="col-md-3"><input name="serial_number" class="form-control" placeholder="Serial Number"></div><div class="col-md-3"><input name="mac_address" class="form-control" placeholder="MAC Address"></div><div class="col-md-2"><input name="rx_power" type="number" step="0.01" class="form-control" placeholder="RX dBm"></div><div class="col-md-2"><input name="tx_power" type="number" step="0.01" class="form-control" placeholder="TX dBm"></div><div class="col-md-2"><input name="temperature" type="number" step="0.01" class="form-control" placeholder="Suhu °C"></div><div class="col-md-2"><input name="uptime" class="form-control" placeholder="Uptime"></div><div class="col-md-2"><select name="status" class="form-select"><option value="unknown">Unknown</option><option value="online">Online</option><option value="offline">Offline</option></select></div><div class="col-md-3"><button class="btn btn-primary">Simpan ONU</button></div></div></form>@endif
<div class="table-responsive rounded"><table class="table table-sm align-middle"><thead><tr><th>OLT</th><th>Pelanggan</th><th>ONU</th><th>SN / MAC</th><th>RX / TX dBm</th><th>Suhu</th><th>Status</th>@if(in_array($billingUser?->role,['super_admin','admin'],true))<th>Aksi</th>@endif</tr></thead><tbody>@forelse($onus as $onu)<tr><td>{{$onu->olt->name}}</td><td>{{$onu->customer?->name ?? '—'}}</td><td>{{$onu->name ?? $onu->pon_port.'/'.$onu->onu_id}}</td><td>{{$onu->serial_number}}<div class="text-muted small">{{$onu->mac_address}}</div></td><td>{{$onu->rx_power ?? '—'}} / {{$onu->tx_power ?? '—'}}</td><td>{{$onu->temperature ?? '—'}} °C</td><td>{{$onu->status}}</td>
@if(in_array($billingUser?->role,['super_admin','admin'],true))
<td class="text-nowrap">
    <details>
        <summary class="btn btn-sm btn-outline-secondary">Edit</summary>
        <form method="POST" action="{{route('onus.update',$onu)}}" class="card card-body mt-2 d-grid gap-2" style="min-width:280px">
            @csrf @method('PUT')
            <label class="form-label mb-0" for="onu-olt-{{$onu->id}}">OLT</label>
            <select id="onu-olt-{{$onu->id}}" name="olt_id" class="form-select form-select-sm" required>
                @foreach($olts as $olt)<option value="{{$olt->id}}" @selected((int)$onu->olt_id===(int)$olt->id)>{{$olt->name}}</option>@endforeach
            </select>
            <label class="form-label mb-0" for="onu-customer-{{$onu->id}}">Pelanggan</label>
            <select id="onu-customer-{{$onu->id}}" name="customer_id" class="form-select form-select-sm">
                <option value="">Tidak ditautkan</option>
                @foreach($customers as $customer)<option value="{{$customer->id}}" @selected((int)$onu->customer_id===(int)$customer->id)>{{$customer->customer_code}} — {{$customer->name}}</option>@endforeach
            </select>
            <label class="form-label mb-0" for="onu-name-{{$onu->id}}">Nama ONU</label>
            <input id="onu-name-{{$onu->id}}" name="name" value="{{$onu->name}}" class="form-control form-control-sm" maxlength="120">
            <label class="form-label mb-0" for="onu-pon-{{$onu->id}}">Port PON</label>
            <input id="onu-pon-{{$onu->id}}" name="pon_port" value="{{$onu->pon_port}}" class="form-control form-control-sm" required maxlength="50">
            <label class="form-label mb-0" for="onu-id-{{$onu->id}}">ONU ID</label>
            <input id="onu-id-{{$onu->id}}" name="onu_id" value="{{$onu->onu_id}}" class="form-control form-control-sm" required maxlength="50">
            <label class="form-label mb-0" for="onu-sn-{{$onu->id}}">Serial Number</label>
            <input id="onu-sn-{{$onu->id}}" name="serial_number" value="{{$onu->serial_number}}" class="form-control form-control-sm" maxlength="100">
            <label class="form-label mb-0" for="onu-mac-{{$onu->id}}">MAC Address</label>
            <input id="onu-mac-{{$onu->id}}" name="mac_address" value="{{$onu->mac_address}}" class="form-control form-control-sm">
            <div class="row g-2">
                <div class="col-4"><label class="form-label mb-0" for="onu-rx-{{$onu->id}}">RX dBm</label><input id="onu-rx-{{$onu->id}}" name="rx_power" type="number" step="0.01" value="{{$onu->rx_power}}" class="form-control form-control-sm"></div>
                <div class="col-4"><label class="form-label mb-0" for="onu-tx-{{$onu->id}}">TX dBm</label><input id="onu-tx-{{$onu->id}}" name="tx_power" type="number" step="0.01" value="{{$onu->tx_power}}" class="form-control form-control-sm"></div>
                <div class="col-4"><label class="form-label mb-0" for="onu-temp-{{$onu->id}}">Suhu °C</label><input id="onu-temp-{{$onu->id}}" name="temperature" type="number" step="0.01" value="{{$onu->temperature}}" class="form-control form-control-sm"></div>
            </div>
            <label class="form-label mb-0" for="onu-uptime-{{$onu->id}}">Uptime</label>
            <input id="onu-uptime-{{$onu->id}}" name="uptime" value="{{$onu->uptime}}" class="form-control form-control-sm" maxlength="100">
            <label class="form-label mb-0" for="onu-status-{{$onu->id}}">Status</label>
            <select id="onu-status-{{$onu->id}}" name="status" class="form-select form-select-sm" required>
                @foreach(['unknown'=>'Unknown','online'=>'Online','offline'=>'Offline'] as $value=>$label)<option value="{{$value}}" @selected($onu->status===$value)>{{$label}}</option>@endforeach
            </select>
            <button class="btn btn-sm btn-primary">Simpan perubahan</button>
        </form>
    </details>
    <form method="POST" action="{{route('onus.destroy',$onu)}}" class="d-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="Hapus ONU {{$onu->name ?: $onu->pon_port.'/'.$onu->onu_id}}? Referensi ONU pada pelanggan akan dibersihkan.">
        @csrf @method('DELETE')
        <button class="btn btn-sm btn-outline-danger">Hapus</button>
    </form>
</td>
@endif
</tr>@empty<tr><td colspan="{{in_array($billingUser?->role,['super_admin','admin'],true) ? 8 : 7}}" class="text-center p-4">Belum ada data ONU.</td></tr>@endforelse</tbody></table></div>{{ $onus->links() }}
@endsection
