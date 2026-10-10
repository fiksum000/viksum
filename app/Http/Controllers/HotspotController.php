<?php

namespace App\Http\Controllers;

use App\Models\HotspotVoucher;
use App\Models\Router;
use App\Services\RouterOsService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HotspotController extends Controller
{
    public function index(Request $request)
    {
        return view('hotspot.index', [
            'routers' => Router::where('enabled', true)->orderBy('name')->get(),
            'vouchers' => HotspotVoucher::with('router')->latest()->paginate(100),
            'canManageVouchers' => in_array($request->attributes->get('billing_user')?->role, ['super_admin', 'admin'], true),
        ]);
    }

    public function generate(Request $request, RouterOsService $routerOs)
    {
        $data = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'profile' => 'required|string|max:120',
            'quantity' => 'required|integer|min:1|max:200',
            'prefix' => 'nullable|string|alpha_dash|max:12',
        ]);
        $router = Router::findOrFail($data['router_id']);

        if (! $router->enabled) {
            return back()->withInput()->with('error', 'Router yang dipilih sedang dinonaktifkan.');
        }

        // Validate once before the batch so a typo cannot create partial batches.
        try {
            $routerOs->assertHotspotProfileExists($router, $data['profile']);
        } catch (\\Throwable $exception) {
            report($exception);
            return back()->withInput()->with('error', 'Profil Hotspot tidak ditemukan atau router tidak dapat diakses. Periksa nama profil dan koneksi RouterOS.');
        }

        $created = 0;
        $pendingUsername = null;

        try {
            for ($i = 0; $i < (int) $data['quantity']; $i++) {
                $username = strtoupper(($data['prefix'] ?? 'WIFI').Str::random(6));
                $password = Str::random(8);
                $pendingUsername = $username;

                $routerOs->createHotspotUser($router, $username, $password, $data['profile'], 'Billing voucher', true);
                HotspotVoucher::create([
                    'router_id' => $router->id,
                    'created_by' => $request->session()->get('user_id'),
                    'username' => $username,
                    'password' => $password,
                    'profile' => $data['profile'],
                ]);

                $created++;
                $pendingUsername = null;
            }
        } catch (\\Throwable $exception) {
            report($exception);
            // Best effort cleanup if RouterOS created the account but the local row failed.
            if ($pendingUsername !== null) {
                try {
                    $routerOs->disconnectHotspotActive($router, $pendingUsername);
                    $routerOs->deleteHotspotUser($router, $pendingUsername);
                } catch (\\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            Audit::log('hotspot.vouchers_generated', HotspotVoucher::class, null, ['router_id' => $router->id, 'count' => $created, 'partial' => true]);
            return back()->with('error', "RouterOS menghentikan pembuatan setelah {$created} voucher. Periksa router lalu cek daftar voucher.");
        }

        Audit::log('hotspot.vouchers_generated', HotspotVoucher::class, null, ['router_id' => $router->id, 'count' => $created]);
        return back()->with('success', "{$created} voucher dibuat di router {$router->name}.");
    }

    public function toggle(HotspotVoucher $voucher, RouterOsService $routerOs)
    {
        $enable = $voucher->status !== 'active';
        $routerOs->setHotspotUserEnabled($voucher->router, $voucher->username, $enable);

        if (! $enable) {

            // Disabled credentials cannot be reused after their live session is removed.

            $routerOs->disconnectHotspotActive($voucher->router, $voucher->username);

        }

        $voucher->update(['status' => $enable ? 'active' : 'disabled']);
        Audit::log('hotspot.voucher_toggled', HotspotVoucher::class, $voucher->id, ['status' => $voucher->status]);
        return back()->with('success', 'Status voucher diperbarui di router dan Billing.');
    }

    public function destroy(HotspotVoucher $voucher, RouterOsService $routerOs)
    {
        // Remove the live session before deleting the credential and local record.

        $routerOs->disconnectHotspotActive($voucher->router, $voucher->username);

        $routerOs->deleteHotspotUser($voucher->router, $voucher->username);

        Audit::log('hotspot.voucher_deleted', HotspotVoucher::class, $voucher->id);
        $voucher->delete();
        return back()->with('success', 'Voucher dihapus dari router dan Billing.');
    }

    public function print(Request $request)
    {
        $requested = $request->query('ids', []);
        $requested = is_array($requested) ? $requested : explode(',', (string) $requested);
        $ids = array_slice(array_filter(array_map('intval', $requested)), 0, 200);
        if (!$ids) return back()->with('error', 'Pilih voucher yang akan dicetak.');
        $vouchers = HotspotVoucher::with('router')->whereIn('id', $ids)->get();
        abort_if($vouchers->isEmpty(), 404);
        HotspotVoucher::whereIn('id', $vouchers->modelKeys())->update(['printed_at' => now()]);
        return Pdf::loadView('hotspot.vouchers-pdf', compact('vouchers'))->setPaper('a4')->stream('voucher-hotspot.pdf');
    }

    public function active(Router $router, RouterOsService $routerOs)
    {
        return response()->json(['router' => $router->name, 'users' => $routerOs->listHotspotActive($router)]);
    }
}
