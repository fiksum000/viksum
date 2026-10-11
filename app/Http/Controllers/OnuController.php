<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Onu;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnuController extends Controller
{
    public function index()
    {
        return view('onus.index', [
            'onus' => Onu::with(['olt', 'customer'])->orderBy('olt_id')->orderBy('pon_port')->paginate(100),
            'olts' => Olt::orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(['id', 'customer_code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $onu = DB::transaction(function () use ($data): Onu {
            $onu = Onu::create($data);
            $this->syncCustomerMetadata($onu);
            return $onu;
        });

        Audit::log('onu.created', Onu::class, $onu->id);
        return back()->with('success', 'Data ONU disimpan dan relasi pelanggan disinkronkan.');
    }

    public function update(Request $request, Onu $onu)
    {
        $data = $this->validated($request, $onu);
        $previousCustomerId = $onu->customer_id;

        $onu = DB::transaction(function () use ($data, $onu, $previousCustomerId): Onu {
            $onu->update($data);

            if ($previousCustomerId && (int) $previousCustomerId !== (int) $onu->customer_id) {
                $this->clearCustomerMetadata((int) $previousCustomerId);
            }
            $this->syncCustomerMetadata($onu);

            return $onu->fresh(['olt', 'customer']);
        });

        Audit::log('onu.updated', Onu::class, $onu->id);
        return back()->with('success', 'Data ONU diperbarui dan relasi pelanggan disinkronkan.');
    }

    public function destroy(Onu $onu)
    {
        DB::transaction(function () use ($onu): void {
            if ($onu->customer_id) {
                $this->clearCustomerMetadata((int) $onu->customer_id);
            }
            $onu->delete();
        });

        Audit::log('onu.deleted', Onu::class, $onu->id);
        return back()->with('success', 'ONU dihapus dan referensi jaringan pelanggan dibersihkan.');
    }

    private function validated(Request $request, ?Onu $onu = null): array
    {
        return $request->validate([
            'olt_id' => ['required', 'integer', 'exists:olts,id'],
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
                Rule::unique('onus', 'customer_id')->ignore($onu?->id),
            ],
            'name' => 'nullable|string|max:120',
            'pon_port' => 'required|string|max:50',
            'onu_id' => 'required|string|max:50',
            'serial_number' => 'nullable|string|max:100',
            'mac_address' => 'nullable|mac_address',
            'rx_power' => 'nullable|numeric|between:-60,20',
            'tx_power' => 'nullable|numeric|between:-60,20',
            'temperature' => 'nullable|numeric|between:-50,150',
            'uptime' => 'nullable|string|max:100',
            'status' => 'required|in:online,offline,unknown',
        ]);
    }

    private function syncCustomerMetadata(Onu $onu): void
    {
        if (!$onu->customer_id) {
            return;
        }

        $customer = Customer::query()->lockForUpdate()->findOrFail($onu->customer_id);
        $olt = $onu->olt()->first();

        $customer->update([
            'olt_id' => $onu->olt_id,
            'olt_name' => $olt?->name,
            'pon_port' => $onu->pon_port,
            'onu_id' => $onu->onu_id,
            'onu_sn' => $onu->serial_number,
        ]);
    }

    private function clearCustomerMetadata(int $customerId): void
    {
        $customer = Customer::query()->lockForUpdate()->find($customerId);
        if (!$customer) {
            return;
        }

        $customer->update([
            'olt_id' => null,
            'olt_name' => null,
            'pon_port' => null,
            'onu_id' => null,
            'onu_sn' => null,
        ]);
    }
}
