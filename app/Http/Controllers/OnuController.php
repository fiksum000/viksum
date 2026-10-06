<?php

namespace App\Http\Controllers;

use App\Models\Olt;
use App\Models\Onu;
use App\Models\Customer;
use App\Support\Audit;
use Illuminate\Http\Request;

class OnuController extends Controller
{
    public function index()
    {
        return view('onus.index', ['onus' => Onu::with(['olt', 'customer'])->orderBy('olt_id')->orderBy('pon_port')->paginate(100), 'olts' => Olt::orderBy('name')->get(), 'customers' => Customer::orderBy('name')->get(['id', 'customer_code', 'name'])]);
    }

    public function store(Request $request)
    {
        $onu = Onu::create($this->validated($request));
        Audit::log('onu.created', Onu::class, $onu->id);
        return back()->with('success', 'Data ONU disimpan.');
    }

    public function update(Request $request, Onu $onu)
    {
        $onu->update($this->validated($request));
        Audit::log('onu.updated', Onu::class, $onu->id);
        return back()->with('success', 'Data dan telemetri ONU diperbarui.');
    }

    public function destroy(Onu $onu)
    {
        Audit::log('onu.deleted', Onu::class, $onu->id);
        $onu->delete();
        return back()->with('success', 'ONU dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'olt_id' => 'required|exists:olts,id', 'customer_id' => 'nullable|exists:customers,id',
            'name' => 'nullable|string|max:120', 'pon_port' => 'required|string|max:50', 'onu_id' => 'required|string|max:50',
            'serial_number' => 'nullable|string|max:100', 'mac_address' => 'nullable|mac_address',
            'rx_power' => 'nullable|numeric|between:-60,20', 'tx_power' => 'nullable|numeric|between:-60,20',
            'temperature' => 'nullable|numeric|between:-50,150', 'uptime' => 'nullable|string|max:100',
            'status' => 'required|in:online,offline,unknown',
        ]);
    }
}
