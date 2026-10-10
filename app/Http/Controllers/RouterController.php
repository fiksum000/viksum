<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\RouterOsService;
use App\Support\IsolationScript;
use Illuminate\Http\Request;

class RouterController extends Controller
{
    public function index()
    {
        $billingUrl = rtrim((string) config('app.url'), '/');
        $billingHost = parse_url($billingUrl, PHP_URL_HOST) ?: 'billing.example.com';
        $isolationProfile = (string) config('billing.isolation_profile', 'ISOLIR');

        return view('routers.index', [
            'routers' => Router::latest()->get(),
            'isolationScript' => IsolationScript::forBillingUrl($billingUrl, $billingHost, $isolationProfile),
            'billingHost' => $billingHost,
            'isolationMethod' => config('billing.isolation_method', 'profile'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'ssl' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'traffic_interface' => ['nullable', 'string', 'max:120'],
        ]);

        Router::create($data);

        return back()->with('success', 'Router disimpan.');
    }

    public function update(Request $request, Router $router)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string'],
            'ssl' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'traffic_interface' => ['nullable', 'string', 'max:120'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $router->update($data);

        return back()->with('success', 'Router diperbarui.');
    }

    public function updateTrafficInterface(Request $request, Router $router)
    {
        $data = $request->validate([
            'traffic_interface' => ['required', 'string', 'max:120'],
        ]);

        $router->update($data);

        return back()->with('success', 'Interface trafik router diperbarui.');
    }

    public function test(Router $router, RouterOsService $api)
    {
        $result = $api->testConnection($router);
        $message = $result['ok']
            ? 'Koneksi berhasil. Identity/resource terbaca.'
            : 'Koneksi gagal. Periksa host, port, kredensial, dan log aplikasi.';

        return back()->with($result['ok'] ? 'success' : 'error', $message);
    }

    public function destroy(Router $router)
    {
        $inUse = Customer::where('router_id', $router->id)->exists()
            || Package::where('router_id', $router->id)->exists()
            || HotspotProfile::where('router_id', $router->id)->exists()
            || HotspotVoucher::where('router_id', $router->id)->exists();

        if ($inUse) {
            return back()->with('warning', 'Router masih dipakai pelanggan, paket, profil, atau voucher. Pindahkan data atau hapus relasinya terlebih dahulu.');
        }

        $router->delete();

        return back()->with('success', 'Router yang tidak memiliki relasi layanan berhasil dihapus.');
    }
}
