<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Router;
use App\Services\RouterOsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PackageController extends Controller
{
    public function index()
    {
        return view('packages.index', [
            'packages' => Package::with('router')->orderBy('name')->get(),
            'routers' => Router::where('enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, RouterOsService $routerOs)
    {
        Package::create($this->validated($request, $routerOs));
        return back()->with('success', 'Paket billing dan profil MikroTik berhasil ditautkan.');
    }

    public function update(Request $request, Package $package, RouterOsService $routerOs)
    {
        $package->update($this->validated($request, $routerOs, $package));
        return back()->with('success', 'Paket dan pemetaan profil berhasil diperbarui.');
    }

    public function destroy(Package $package)
    {
        if ($package->customers()->exists()) {
            return back()->with('warning', 'Paket masih dipakai pelanggan. Pindahkan pelanggan ke paket lain sebelum menghapusnya.');
        }

        $package->delete();
        return back()->with('success', 'Paket dihapus.');
    }

    private function validated(Request $request, RouterOsService $routerOs, ?Package $package = null): array
    {
        $data = $request->validate([
            'name' => 'required|max:100',
            'price' => 'required|integer|min:0',
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'normal_profile' => 'required|string|max:120',
            'normal_speed' => 'nullable|max:50',
            'burst_enabled' => 'nullable|boolean',
            'burst_limit' => 'nullable|max:50',
            'burst_threshold' => 'nullable|max:50',
            'priority' => 'required|integer|min:1|max:8',
            'fup_enabled' => 'nullable|boolean',
            'fup_limit_bytes' => 'nullable|integer|min:0',
            'fup_speed_after' => 'nullable|max:50',
        ]);

        $router = Router::where('enabled', true)->find($data['router_id']);
        if (!$router) {
            throw ValidationException::withMessages(['router_id' => 'Pilih router yang aktif.']);
        }

        try {
            $profiles = collect($routerOs->listPppProfiles($router))
                ->pluck('name')->filter(fn ($name) => is_string($name) && $name !== '')->values();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['router_id' => 'Profil router belum bisa dibaca. Periksa koneksi MikroTik, lalu coba lagi.']);
        }

        if (!$profiles->contains($data['normal_profile'])) {
            throw ValidationException::withMessages(['normal_profile' => 'Pilih profil yang benar-benar tersedia pada router tersebut.']);
        }
        if (($data['fup_enabled'] ?? false) && blank($data['fup_speed_after'] ?? null)) {
            throw ValidationException::withMessages(['fup_speed_after' => 'Pilih profil PPP untuk diterapkan setelah batas FUP tercapai.']);
        }
        if (filled($data['fup_speed_after'] ?? null) && !$profiles->contains($data['fup_speed_after'])) {
            throw ValidationException::withMessages(['fup_speed_after' => 'Profil setelah FUP harus tersedia di router yang sama.']);
        }

        if ($package && $package->customers()->whereNotNull('router_id')->where('router_id', '!=', $router->id)->exists()) {
            throw ValidationException::withMessages(['router_id' => 'Paket ini dipakai pelanggan pada router lain. Pindahkan pelanggan terlebih dahulu sebelum mengubah router paket.']);
        }

        return $data;
    }
}
