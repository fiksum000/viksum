<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use App\Models\Router;
use App\Services\HotspotVoucherLifecycleService;
use App\Services\RouterOsService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotspotController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'profile_id' => ['nullable', 'integer', 'exists:hotspot_profiles,id'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'disabled', 'expired'])],
        ]);

        $canManage = in_array($request->attributes->get('billing_user')?->role, ['super_admin', 'admin'], true);
        $voucherQuery = HotspotVoucher::with(['router', 'hotspotProfile'])->latest();

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $voucherQuery->where(fn ($query) => $query
                ->where('username', 'like', '%'.$search.'%')
                ->orWhere('comment', 'like', '%'.$search.'%'));
        }
        if (filled($filters['router_id'] ?? null)) {
            $voucherQuery->where('router_id', $filters['router_id']);
        }
        if (filled($filters['profile_id'] ?? null)) {
            $voucherQuery->where('hotspot_profile_id', $filters['profile_id']);
        }
        if (filled($filters['status'] ?? null)) {
            $voucherQuery->where('status', $filters['status']);
        }

        return view('hotspot.index', [
            'routers' => Router::where('enabled', true)->orderBy('name')->get(),
            'profiles' => HotspotProfile::with('router')->orderBy('router_id')->orderBy('name')->get(),
            'generatorProfiles' => HotspotProfile::with('router')
                ->where('enabled', true)->where('sync_status', 'synced')
                ->whereHas('router', fn ($query) => $query->where('enabled', true))
                ->orderBy('router_id')->orderBy('name')->get(),
            'vouchers' => $voucherQuery->paginate(100)->withQueryString(),
            'canManageVouchers' => $canManage,
            'filters' => $filters,
            'stats' => [
                'profiles' => HotspotProfile::count(),
                'vouchers' => HotspotVoucher::count(),
                'active' => HotspotVoucher::where('status', 'active')->count(),
                'expired' => HotspotVoucher::where('status', 'expired')->count(),
                'sync_failed' => HotspotVoucher::whereIn('sync_status', ['failed', 'missing'])->count()
                    + HotspotProfile::whereIn('sync_status', ['failed', 'missing'])->count(),
            ],
        ]);
    }

    public function profileStore(Request $request, RouterOsService $routerOs)
    {
        $data = $this->validatedProfile($request, true);
        $router = Router::findOrFail($data['router_id']);
        if (! $router->enabled) {
            return back()->withInput()->with('error', 'Router sedang dinonaktifkan.');
        }

        $profile = HotspotProfile::create($data + ['sync_status' => 'pending']);
        try {
            $routerOs->syncHotspotProfile($profile->fresh('router'));
            $profile->update(['sync_status' => 'synced', 'sync_error' => null, 'last_synced_at' => now()]);
            Audit::log('hotspot.profile_created', HotspotProfile::class, $profile->id, [
                'router_id' => $profile->router_id, 'name' => $profile->name,
            ]);

            return redirect()->route('hotspot.index')->with('success', "Profil {$profile->name} disimpan di billing dan disinkronkan ke {$router->name}.");
        } catch (Throwable $exception) {
            report($exception);
            $profile->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return redirect()->route('hotspot.index')->with('warning', "Profil {$profile->name} tersimpan di billing, tetapi sinkronisasi MikroTik gagal. Periksa router lalu gunakan Sinkronkan ulang.");
        }
    }

    public function profileUpdate(Request $request, HotspotProfile $hotspotProfile, RouterOsService $routerOs)
    {
        $data = $this->validatedProfile($request, false, $hotspotProfile);
        $hotspotProfile->update($data + ['sync_status' => 'pending', 'sync_error' => null]);

        try {
            $routerOs->syncHotspotProfile($hotspotProfile->fresh('router'));
            $hotspotProfile->update(['sync_status' => 'synced', 'sync_error' => null, 'last_synced_at' => now()]);
            Audit::log('hotspot.profile_updated', HotspotProfile::class, $hotspotProfile->id);

            return redirect()->route('hotspot.index')->with('success', "Profil {$hotspotProfile->name} diperbarui dan disinkronkan.");
        } catch (Throwable $exception) {
            report($exception);
            $hotspotProfile->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return redirect()->route('hotspot.index')->with('warning', "Pengaturan tersimpan di billing tetapi sinkronisasi {$hotspotProfile->name} gagal. Periksa router dan coba Sinkronkan ulang.");
        }
    }

    public function profileSync(HotspotProfile $hotspotProfile, RouterOsService $routerOs)
    {
        try {
            $routerOs->syncHotspotProfile($hotspotProfile->fresh('router'));
            $hotspotProfile->update(['sync_status' => 'synced', 'sync_error' => null, 'last_synced_at' => now()]);
            Audit::log('hotspot.profile_synced', HotspotProfile::class, $hotspotProfile->id);

            return back()->with('success', "Profil {$hotspotProfile->name} berhasil disinkronkan ke MikroTik.");
        } catch (Throwable $exception) {
            report($exception);
            $hotspotProfile->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return back()->with('error', 'Sinkronisasi profil gagal. Periksa koneksi, hak API, dan log aplikasi.');
        }
    }

    public function profileDestroy(HotspotProfile $hotspotProfile, RouterOsService $routerOs)
    {
        if ($hotspotProfile->vouchers()->exists()
            || Customer::where('hotspot_profile_id', $hotspotProfile->id)->exists()) {
            return back()->with('error', 'Profil tidak bisa dihapus karena masih dipakai voucher atau pelanggan. Nonaktifkan profil atau pindahkan akun terlebih dahulu.');
        }

        try {
            $routerOs->deleteHotspotProfile($hotspotProfile);
            Audit::log('hotspot.profile_deleted', HotspotProfile::class, $hotspotProfile->id, [
                'router_id' => $hotspotProfile->router_id, 'name' => $hotspotProfile->name,
            ]);
            $hotspotProfile->delete();

            return back()->with('success', 'Profil dihapus dari billing dan MikroTik.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Profil belum dihapus. Pastikan tidak ada akun RouterOS yang masih memakai profil ini.');
        }
    }

    public function generate(Request $request, RouterOsService $routerOs, HotspotVoucherLifecycleService $lifecycle)
    {
        $data = $request->validate([
            'profile_id' => 'required|integer|exists:hotspot_profiles,id',
            'quantity' => 'required|integer|min:1|max:200',
            'prefix' => 'nullable|string|alpha_dash|max:12',
        ]);

        $profile = HotspotProfile::with('router')->findOrFail($data['profile_id']);
        if (! $profile->enabled || $profile->sync_status !== 'synced' || ! $profile->router?->enabled) {
            return back()->withInput()->with('error', 'Profil harus aktif dan sudah tersinkron ke router sebelum voucher dibuat.');
        }

        $created = 0;
        $pendingVoucher = null;
        try {
            for ($i = 0; $i < (int) $data['quantity']; $i++) {
                $username = strtoupper(($data['prefix'] ?? 'WIFI').Str::random(6));
                $password = Str::random(8);
                $expiresAt = null;

                if (! $profile->starts_on_first_login && $profile->validity_value && $profile->validity_unit) {
                    $expiresAt = $lifecycle->addValidity(
                        now(config('billing.timezone')),
                        (int) $profile->validity_value,
                        $profile->validity_unit,
                    );
                }

                $pendingVoucher = HotspotVoucher::create([
                    'router_id' => $profile->router_id,
                    'hotspot_profile_id' => $profile->id,
                    'created_by' => $request->session()->get('user_id'),
                    'username' => $username,
                    'password' => $password,
                    'profile' => $profile->name,
                    'comment' => 'VIKSUM:V:PENDING',
                    'status' => 'pending',
                    'sync_status' => 'pending',
                    'expires_at' => $expiresAt,
                    'fup_applied' => false,
                    'total_bytes_used' => 0,
                    'last_bytes_in' => 0,
                    'last_bytes_out' => 0,
                ]);
                $comment = 'VIKSUM:V:'.$pendingVoucher->id;
                $pendingVoucher->update(['comment' => $comment]);

                $routerOs->createHotspotUser(
                    $profile->router,
                    $username,
                    $password,
                    $profile->routerProfileName(),
                    $comment,
                    true,
                );

                $pendingVoucher->update(['status' => 'active', 'sync_status' => 'synced', 'sync_error' => null]);
                $created++;
                $pendingVoucher = null;
            }
        } catch (Throwable $exception) {
            report($exception);
            if ($pendingVoucher) {
                try {
                    $routerOs->deleteManagedHotspotUser(
                        $profile->router,
                        $pendingVoucher->username,
                        $pendingVoucher->comment ?: 'VIKSUM:V:'.$pendingVoucher->id,
                    );
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
                $pendingVoucher->update([
                    'status' => 'disabled',
                    'sync_status' => 'failed',
                    'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            }

            Audit::log('hotspot.vouchers_generated', HotspotVoucher::class, null, [
                'router_id' => $profile->router_id, 'profile_id' => $profile->id, 'count' => $created, 'partial' => true,
            ]);

            return back()->with('error', "Pembuatan berhenti setelah {$created} voucher. Periksa status sinkronisasi voucher yang gagal.");
        }

        Audit::log('hotspot.vouchers_generated', HotspotVoucher::class, null, [
            'router_id' => $profile->router_id, 'profile_id' => $profile->id, 'count' => $created,
        ]);

        return back()->with('success', "{$created} voucher dibuat dari profil billing {$profile->name} dan disinkronkan ke {$profile->router->name}.");
    }

    public function renew(HotspotVoucher $voucher, RouterOsService $routerOs, HotspotVoucherLifecycleService $lifecycle)
    {
        $voucher->load(['router', 'hotspotProfile']);
        $router = $voucher->router;
        $profile = $voucher->hotspotProfile;

        if (! $voucher->hotspot_profile_id || ! $profile) {
            return back()->with('error', 'Voucher lama tanpa profil yang dikelola billing tidak dapat diperpanjang otomatis.');
        }
        if (! in_array($voucher->status, ['active', 'disabled', 'expired'], true)) {
            return back()->with('error', 'Hanya voucher aktif, nonaktif, atau kedaluwarsa yang dapat diperpanjang.');
        }
        if (! $profile->enabled || ! $profile->validity_value || ! $profile->validity_unit) {
            return back()->with('error', 'Profil voucher harus aktif dan memiliki masa berlaku sebelum voucher dapat diperpanjang.');
        }
        if (! $router || ! $router->enabled) {
            return back()->with('error', 'Router voucher tidak tersedia atau sedang dinonaktifkan.');
        }
        if ($profile->starts_on_first_login && ! $voucher->first_login_at) {
            return back()->with('error', 'Voucher ini belum pernah login. Masa berlaku akan mulai saat login pertama, jadi belum perlu diperpanjang.');
        }

        $now = now(config('billing.timezone'));
        $expired = $voucher->status === 'expired' || ($voucher->expires_at && $voucher->expires_at->lte($now));
        $base = $voucher->expires_at && $voucher->expires_at->gt($now)
            ? $voucher->expires_at->copy()
            : $now->copy();
        $newExpiry = $lifecycle->addValidity($base, (int) $profile->validity_value, (string) $profile->validity_unit);
        $expectedComment = $voucher->comment ?: 'VIKSUM:V:'.$voucher->id;
        $hasFupProfile = (int) $profile->fup_limit_bytes > 0
            && filled($profile->fup_upload_speed)
            && filled($profile->fup_download_speed);
        $keepFup = $voucher->fup_applied && $hasFupProfile;
        $targetProfile = $profile->routerProfileName().($keepFup ? '-FUP' : '');

        try {
            $routerOs->syncHotspotProfile($profile->fresh('router'));
            if ($expired) {
                // Revoke any stale session before re-enabling a voucher whose validity had ended.
                $routerOs->disconnectHotspotActive($router, $voucher->username);
            }
            $routerOs->createOrUpdateHotspotUser(
                $router,
                $voucher->username,
                $voucher->password,
                $targetProfile,
                $expectedComment,
                $voucher->username,
                true,
                $expectedComment,
            );

            $profile->update(['sync_status' => 'synced', 'sync_error' => null, 'last_synced_at' => now()]);
            $voucher->update([
                'expires_at' => $newExpiry,
                'status' => 'active',
                'fup_applied' => $keepFup,
                'sync_status' => 'synced',
                'sync_error' => null,
            ]);
            Audit::log('hotspot.voucher_renewed', HotspotVoucher::class, $voucher->id, [
                'expires_at' => $newExpiry->toDateTimeString(),
                'fup_applied' => $keepFup,
            ]);

            return back()->with('success', 'Voucher diperpanjang sampai '.$newExpiry->timezone(config('billing.timezone'))->format('d/m/Y H:i').'. Pemakaian data dan status FUP yang masih berlaku dipertahankan.');
        } catch (Throwable $exception) {
            Log::warning('Hotspot voucher renewal failed', [
                'voucher_id' => $voucher->id,
                'router_id' => $router->id,
                'error' => $exception->getMessage(),
            ]);
            $voucher->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return back()->with('error', 'Voucher belum diperpanjang. Periksa koneksi, kepemilikan akun, dan sinkronisasi profil MikroTik.');
        }
    }
    public function toggle(HotspotVoucher $voucher, RouterOsService $routerOs)
    {
        $expectedComment = $voucher->comment ?: (
            $voucher->hotspot_profile_id ? 'VIKSUM:V:'.$voucher->id : 'Billing voucher'
        );

        if ($voucher->status === 'expired'
            || ($voucher->expires_at && $voucher->expires_at->lte(now(config('billing.timezone'))))) {
            try {
                if ($voucher->hotspot_profile_id) {
                    $routerOs->setManagedHotspotUserEnabled($voucher->router, $voucher->username, false, $expectedComment);
                } else {
                    $routerOs->setHotspotUserEnabled($voucher->router, $voucher->username, false);
                }
                $routerOs->disconnectHotspotActive($voucher->router, $voucher->username);
                $voucher->update(['status' => 'expired']);
            } catch (Throwable $exception) {
                report($exception);
                $voucher->update([
                    'sync_status' => 'failed',
                    'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                return back()->with('error', 'Voucher kedaluwarsa, tetapi penonaktifan router gagal. Periksa status sinkronisasi.');
            }

            return back()->with('error', 'Voucher sudah kedaluwarsa dan tidak bisa diaktifkan lagi.');
        }

        $enable = $voucher->status !== 'active';
        try {
            $profile = $voucher->hotspotProfile;
            if ($profile && $voucher->sync_status !== 'synced') {
                $routerOs->syncHotspotProfile($profile->fresh('router'));
                $routerOs->createOrUpdateHotspotUser(
                    $voucher->router,
                    $voucher->username,
                    $voucher->password,
                    $voucher->fup_applied ? $profile->routerProfileName().'-FUP' : $profile->routerProfileName(),
                    $expectedComment,
                    $voucher->username,
                    $enable,
                    $expectedComment,
                );
                $voucher->update(['sync_status' => 'synced', 'sync_error' => null]);
            } elseif ($profile) {
                $routerOs->setManagedHotspotUserEnabled($voucher->router, $voucher->username, $enable, $expectedComment);
            } else {
                // Legacy voucher records pre-date billing ownership markers.
                $routerOs->setHotspotUserEnabled($voucher->router, $voucher->username, $enable);
            }

            if (! $enable) {
                $routerOs->disconnectHotspotActive($voucher->router, $voucher->username);
            }

            $voucher->update(['status' => $enable ? 'active' : 'disabled']);
            Audit::log('hotspot.voucher_toggled', HotspotVoucher::class, $voucher->id, ['status' => $voucher->status]);

            return back()->with('success', 'Status voucher diperbarui di billing dan MikroTik.');
        } catch (Throwable $exception) {
            report($exception);
            $voucher->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            return back()->with('error', 'Status voucher belum diubah karena pemeriksaan kepemilikan atau operasi MikroTik gagal.');
        }
    }

    public function destroy(HotspotVoucher $voucher, RouterOsService $routerOs)
    {
        $expectedComment = $voucher->comment ?: (
            $voucher->hotspot_profile_id ? 'VIKSUM:V:'.$voucher->id : 'Billing voucher'
        );

        try {
            // For managed vouchers this verifies the RouterOS comment before disconnect/remove.
            // Older records use their original generator comment; if it differs, removal is refused.
            $routerOs->deleteManagedHotspotUser($voucher->router, $voucher->username, $expectedComment);
        } catch (Throwable $exception) {
            report($exception);
            $voucher->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return back()->with('error', 'Voucher tidak dihapus karena kepemilikan akun MikroTik belum bisa dipastikan.');
        }

        Audit::log('hotspot.voucher_deleted', HotspotVoucher::class, $voucher->id, [
            'router_id' => $voucher->router_id,
            'username' => $voucher->username,
        ]);
        $voucher->delete();

        return back()->with('success', 'Voucher dihapus dari billing dan akun milik voucher dibersihkan di MikroTik.');
    }

    public function syncVoucher(HotspotVoucher $voucher, RouterOsService $routerOs)
    {
        $profile = $voucher->hotspotProfile;
        if (! $profile || ! $voucher->router?->enabled) {
            return back()->with('error', 'Profil atau router voucher tidak tersedia; sinkronisasi ulang voucher lama perlu profil pengganti.');
        }
        if ($voucher->status === 'expired' || ($voucher->expires_at && $voucher->expires_at->lte(now(config('billing.timezone'))))) {
            $expectedComment = $voucher->comment ?: 'VIKSUM:V:'.$voucher->id;
            try {
                $routerOs->setManagedHotspotUserEnabled(
                    $voucher->router,
                    $voucher->username,
                    false,
                    $expectedComment,
                );
                $routerOs->disconnectHotspotActive($voucher->router, $voucher->username);
                $voucher->update(['status' => 'expired', 'sync_status' => 'synced', 'sync_error' => null]);
                return back()->with('error', 'Voucher kedaluwarsa; akun MikroTik sudah dinonaktifkan dan sesi diputus.');
            } catch (Throwable $exception) {
                report($exception);
                $voucher->update([
                    'sync_status' => 'failed',
                    'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
                return back()->with('error', 'Voucher sudah kedaluwarsa, tetapi penonaktifan MikroTik gagal. Periksa status sinkronisasi.');
            }
        }

        try {
            $routerOs->syncHotspotProfile($profile->fresh('router'));
            $routerOs->createOrUpdateHotspotUser(
                $voucher->router,
                $voucher->username,
                $voucher->password,
                $voucher->fup_applied ? $profile->routerProfileName().'-FUP' : $profile->routerProfileName(),
                $voucher->comment ?: 'VIKSUM:V:'.$voucher->id,
                $voucher->username,
                $voucher->status === 'active',
                $voucher->comment ?: 'VIKSUM:V:'.$voucher->id,
            );
            $voucher->update(['sync_status' => 'synced', 'sync_error' => null]);
            Audit::log('hotspot.voucher_synced', HotspotVoucher::class, $voucher->id);

            return back()->with('success', 'Voucher berhasil disinkronkan ulang ke MikroTik.');
        } catch (Throwable $exception) {
            report($exception);
            $voucher->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            return back()->with('error', 'Sinkronisasi voucher gagal; periksa router dan log aplikasi.');
        }
    }

    public function print(Request $request)
    {
        $requested = $request->query('ids', []);
        $requested = is_array($requested) ? $requested : explode(',', (string) $requested);
        $ids = array_slice(array_filter(array_map('intval', $requested)), 0, 200);
        if (! $ids) {
            return back()->with('error', 'Pilih voucher yang akan dicetak.');
        }
        $vouchers = HotspotVoucher::with(['router', 'hotspotProfile'])->whereIn('id', $ids)->get();
        abort_if($vouchers->isEmpty(), 404);
        HotspotVoucher::whereIn('id', $vouchers->modelKeys())->update(['printed_at' => now()]);

        return Pdf::loadView('hotspot.vouchers-pdf', compact('vouchers'))->setPaper('a4')->stream('voucher-hotspot.pdf');
    }

    public function active(Router $router, RouterOsService $routerOs)
    {
        abort_unless($router->enabled, 404);
        return response()->json(['router' => $router->name, 'users' => $routerOs->listHotspotActive($router)]);
    }

    private function validatedProfile(Request $request, bool $creating, ?HotspotProfile $profile = null): array
    {
        $data = $request->validate([
            'router_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:routers,id'],
            'name' => [$creating ? 'required' : 'prohibited', 'string', 'max:100',
                Rule::unique('hotspot_profiles', 'name')->where(fn ($query) => $query->where('router_id', $request->input('router_id')))],
            'download_speed' => ['required', 'string', 'max:30', 'regex:/^\d+(?:\.\d+)?[kKmMgG]?$/'],
            'upload_speed' => ['required', 'string', 'max:30', 'regex:/^\d+(?:\.\d+)?[kKmMgG]?$/'],
            'shared_users' => ['required', 'integer', 'min:1', 'max:200'],
            'fup_limit_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fup_download_speed' => ['nullable', 'string', 'max:30', 'regex:/^\d+(?:\.\d+)?[kKmMgG]?$/'],
            'fup_upload_speed' => ['nullable', 'string', 'max:30', 'regex:/^\d+(?:\.\d+)?[kKmMgG]?$/'],
            'validity_value' => ['nullable', 'integer', 'min:1', 'max:87600'],
            'validity_unit' => ['nullable', Rule::in(['hour', 'day', 'month'])],
            'starts_on_first_login' => ['nullable', 'boolean'],
            'bind_mac' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if ($creating && ! Router::whereKey($data['router_id'])->where('enabled', true)->exists()) {
            throw ValidationException::withMessages(['router_id' => 'Pilih router yang aktif.']);
        }

        $fupLimit = (float) ($data['fup_limit_gb'] ?? 0);
        $fupDown = $data['fup_download_speed'] ?? null;
        $fupUp = $data['fup_upload_speed'] ?? null;
        if ($fupLimit > 0 && (! filled($fupDown) || ! filled($fupUp))) {
            throw ValidationException::withMessages([
                'fup_download_speed' => 'Isi kecepatan download dan upload setelah FUP, atau kosongkan batas FUP.',
            ]);
        }
        if (($data['validity_value'] ?? null) && ! filled($data['validity_unit'] ?? null)) {
            throw ValidationException::withMessages(['validity_unit' => 'Pilih satuan masa berlaku: jam, hari, atau bulan.']);
        }
        if (! ($data['validity_value'] ?? null) && filled($data['validity_unit'] ?? null)) {
            throw ValidationException::withMessages(['validity_value' => 'Isi durasi masa berlaku atau kosongkan satuannya.']);
        }

        unset($data['fup_limit_gb']);
        $data['fup_limit_bytes'] = $fupLimit > 0 ? (int) round($fupLimit * 1073741824) : null;
        $data['fup_download_speed'] = $fupLimit > 0 ? $fupDown : null;
        $data['fup_upload_speed'] = $fupLimit > 0 ? $fupUp : null;
        $data['starts_on_first_login'] = (bool) ($data['starts_on_first_login'] ?? false);
        $data['bind_mac'] = (bool) ($data['bind_mac'] ?? false);
        $data['enabled'] = (bool) ($data['enabled'] ?? false);

        if (! $creating) {
            unset($data['router_id'], $data['name']);
            // Name and router are intentionally stable; attached voucher profiles must not drift.
            $data['enabled'] = $request->boolean('enabled', $profile?->enabled ?? true);
        }

        return $data;
    }
}
