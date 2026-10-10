<?php

namespace App\Http\Controllers;

use App\Models\Olt;
use App\Models\Customer;
use App\Support\Audit;
use Illuminate\Http\Request;

class OltController extends Controller
{
    public function index()
    {
        return view('olts.index', ['olts' => Olt::withCount('onus')->orderBy('name')->paginate(50)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'vendor' => 'nullable|string|max:120', 'model' => 'nullable|string|max:120', 'host' => 'nullable|string|max:255', 'management_protocol' => 'required|in:manual,snmp,http,ssh', 'management_port' => 'nullable|integer|min:1|max:65535', 'username' => 'nullable|string|max:120', 'password' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000']);
        $olt = Olt::create($data + ['status' => 'unknown', 'enabled' => true]);
        Audit::log('olt.created', Olt::class, $olt->id);
        return back()->with('success', 'Data OLT disimpan.');
    }

    public function update(Request $request, Olt $olt)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'vendor' => 'nullable|string|max:120', 'model' => 'nullable|string|max:120', 'host' => 'nullable|string|max:255', 'management_protocol' => 'required|in:manual,snmp,http,ssh', 'management_port' => 'nullable|integer|min:1|max:65535', 'username' => 'nullable|string|max:120', 'password' => 'nullable|string|max:255', 'status' => 'required|in:online,offline,unknown', 'notes' => 'nullable|string|max:5000']);
        if (blank($data['password'] ?? null)) unset($data['password']);
        $olt->update($data);
        Audit::log('olt.updated', Olt::class, $olt->id);
        return back()->with('success', 'Data OLT diperbarui.');
    }

    public function destroy(Olt $olt)
    {
        if ($olt->onus()->exists() || Customer::where('olt_id', $olt->id)->exists()) {
            return back()->with('warning', 'OLT masih memiliki ONU atau relasi pelanggan. Pindahkan relasi atau hapus inventaris ONU terlebih dahulu.');
        }

        Audit::log('olt.deleted', Olt::class, $olt->id);
        $olt->delete();
        return back()->with('success', 'OLT yang tidak memiliki relasi ONU/pelanggan berhasil dihapus.');
    }
}
