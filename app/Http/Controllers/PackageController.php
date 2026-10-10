<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FupState;
use App\Models\Package;
use App\Models\Router;
use App\Services\FupService;
use App\Services\RouterOsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PackageController extends Controller
{
    public function index()
    {
        return view('packages.index', [
            'packages' => Package::with('router')->withCount('customers')->orderBy('name')->get(),
            'routers' => Router::where('enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, RouterOsService $routerOs)
    {
        $data = $this->validated($request);
        // normal_profile is non-null in legacy databases; it is replaced with the
        // generated, package-owned name immediately after the new row receives its ID.
        $data['normal_profile'] = 'pending';
        $data['fup_speed_after'] = null;

        $package = Package::create($data);
        $package->update([
            'normal_profile' => $package->routerProfileName(),
            'fup_speed_after' => $package->fup_enabled ? $package->routerFupProfileName() : null,
        ]);

        return $this->syncAndRespond($package->fresh('router'), $routerOs, 'created');
    }

    public function update(Request $request, Package $package, RouterOsService $routerOs)
    {
        $data = $this->validated($request, $package);
        $data['normal_profile'] = $package->routerProfileName();
        $data['fup_speed_after'] = $data['fup_enabled'] ? $package->routerFupProfileName() : null;

        // Preserve old router profile names for safe conversion and for reporting.
        $data['legacy_normal_profile'] = $package->legacy_normal_profile
            ?: ($package->sync_status === 'legacy' ? $package->normal_profile : null);
        $data['legacy_fup_profile'] = $package->legacy_fup_profile
            ?: ($package->sync_status === 'legacy' ? $package->fup_speed_after : null);
        $package->update($data);

        return $this->syncAndRespond($package->fresh('router'), $routerOs, 'updated');
    }

    public function sync(Package $package, RouterOsService $routerOs)
    {
        if (blank($package->upload_speed) || blank($package->download_speed)) {
            return back()->with('warning', "Paket {$package->name} masih memakai profil PPP lama. Isi kecepatan upload dan download di pengaturan paket untuk mengubahnya menjadi profil yang dikelola billing.");
        }

        // A legacy package may have been saved before managed profile names were assigned.
        if ($package->sync_status === 'legacy') {
            $package->update([
                'legacy_normal_profile' => $package->legacy_normal_profile ?: $package->normal_profile,
                'legacy_fup_profile' => $package->legacy_fup_profile ?: $package->fup_speed_after,
                'normal_profile' => $package->routerProfileName(),
                'fup_speed_after' => $package->fup_enabled ? $package->routerFupProfileName() : null,
                'sync_status' => 'pending',
                'sync_error' => null,
            ]);
            $package->refresh();
        }

        return $this->syncAndRespond($package->fresh('router'), $routerOs, 'synced');
    }

    public function destroy(Package $package, RouterOsService $routerOs)
    {
        if ($package->customers()->exists()) {
            return back()->with('warning', 'Paket masih dipakai pelanggan. Pindahkan pelanggan ke paket lain sebelum menghapusnya.');
        }

        if ($package->sync_status !== 'legacy' && $package->router) {
            try {
                $routerOs->deleteManagedPppProfile(
                    $package->router,
                    $package->routerProfileName(),
                    'VIKSUM:PACKAGE:'.$package->id.':NORMAL',
                );
                $routerOs->deleteManagedPppProfile(
                    $package->router,
                    $package->routerFupProfileName(),
                    'VIKSUM:PACKAGE:'.$package->id.':FUP',
                );
            } catch (Throwable $exception) {
                report($exception);
                return back()->with('error', 'Paket belum dihapus. Periksa kepemilikan profil dan user PPP yang masih memakai profilnya.');
            }
        }

        $package->delete();
        return back()->with('success', 'Paket billing dihapus. Profil RouterOS yang masih digunakan akun router sengaja dibiarkan aman.');
    }

    private function syncAndRespond(Package $package, RouterOsService $routerOs, string $action)
    {
        try {
            $profileSettingsChanged = $routerOs->syncPppPackageProfiles($package->fresh('router'));
            $package->update([
                'sync_status' => 'synced',
                'sync_error' => null,
                'last_synced_at' => now(),
            ]);

            $errors = $this->syncCustomersToPackage($package->fresh('router'), $routerOs, $profileSettingsChanged);
            $package->update([
                'sync_error' => $errors
                    ? 'Profil router sudah tersinkron. Beberapa secret pelanggan belum dapat disinkronkan: '.implode(' | ', array_slice($errors, 0, 5))
                    : null,
            ]);

            if ($errors) {
                return back()->with('warning', "Paket {$package->name} tersimpan dan profil MikroTik berhasil disinkronkan, tetapi ".count($errors)." pelanggan perlu diperiksa. Lihat catatan sinkronisasi pada paket.");
            }

            $verb = match ($action) {
                'created' => 'dibuat',
                'updated' => 'diperbarui',
                default => 'disinkronkan ulang',
            };

            return back()->with('success', "Paket {$package->name} berhasil {$verb}. Profil PPP dan FUP dikelola billing dan sudah diverifikasi di MikroTik.");
        } catch (Throwable $exception) {
            report($exception);
            $package->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return back()->with('warning', "Paket {$package->name} tersimpan di billing, tetapi profil MikroTik belum berhasil disinkronkan. Periksa router lalu gunakan Sinkron ulang.");
        }
    }

    private function syncCustomersToPackage(Package $package, RouterOsService $routerOs, bool $profileSettingsChanged): array
    {
        $customers = Customer::query()
            ->with('router')
            ->where('package_id', $package->id)
            ->where('service_type', 'pppoe')
            ->get();

        $period = app(FupService::class)->currentPeriod();
        $errors = [];

        foreach ($customers as $customer) {
            $oldNormal = $customer->pppoe_profile_normal;
            $oldFup = $customer->fup_speed_after;

            // Update the billing mapping for every customer, including isolated users.
            // Router assignment for inactive users is deliberately left untouched.
            $customer->update([
                'pppoe_profile_normal' => $package->routerProfileName(),
                'fup_speed_after' => $package->fup_enabled ? $package->routerFupProfileName() : null,
            ]);

            $state = FupState::query()
                ->where('customer_id', $customer->id)
                ->where('period', $period)
                ->first();

            if ($customer->status !== 'active' || !$customer->router || !$customer->pppoe_username) {
                if (!$package->fup_enabled && $state?->limited) {
                    $state->update(['limited' => false]);
                }
                continue;
            }

            $expectedProfiles = array_values(array_unique(array_filter([
                $package->legacy_normal_profile,
                $package->legacy_fup_profile,
                $package->routerProfileName(),
                $package->routerFupProfileName(),
                $oldNormal,
                $oldFup,
            ], fn ($value) => is_string($value) && $value !== '')));

            $targetProfile = $package->fup_enabled && $customer->fup_override !== false && $state?->limited
                ? $package->routerFupProfileName()
                : $package->routerProfileName();

            try {
                $assignmentChanged = $routerOs->setPppProfileIfCurrentProfile(
                    $customer->router,
                    $customer->pppoe_username,
                    $expectedProfiles,
                    $targetProfile,
                );

                // Updating a profile changes its settings, but existing PPP sessions
                // may still be using old dynamic queue values. Reconnect those users.
                if ($profileSettingsChanged && !$assignmentChanged) {
                    $routerOs->disconnectPppActive($customer->router, $customer->pppoe_username);
                }

                if (!$package->fup_enabled && $state?->limited) {
                    DB::table('fup_logs')->insert([
                        'customer_id' => $customer->id,
                        'period' => $period,
                        'action' => 'restored',
                        'total_bytes' => $state->total_bytes,
                        'profile_before' => $package->legacy_fup_profile ?: $oldFup,
                        'profile_after' => $package->routerProfileName(),
                        'details' => 'FUP dimatikan pada paket; profil normal billing dipulihkan',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $state->update(['limited' => false]);
                }
            } catch (Throwable $exception) {
                report($exception);
                $errors[] = $customer->customer_code.' ('.$customer->pppoe_username.'): '.mb_substr($exception->getMessage(), 0, 240);
            }
        }

        return $errors;
    }

    private function validated(Request $request, ?Package $package = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('packages', 'name')->ignore($package?->id)],
            'price' => ['required', 'integer', 'min:0'],
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'upload_speed' => ['required', 'string', 'max:30', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?$/'],
            'download_speed' => ['required', 'string', 'max:30', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?$/'],
            'burst_enabled' => ['nullable', 'boolean'],
            'burst_limit' => ['nullable', 'string', 'max:50', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?(?:\\/\\d+(?:\\.\\d+)?[kKmMgG]?)?$/'],
            'burst_threshold' => ['nullable', 'string', 'max:50', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?(?:\\/\\d+(?:\\.\\d+)?[kKmMgG]?)?$/'],
            'burst_time' => ['nullable', 'string', 'max:8', 'regex:/^\\d+(?:\\.\\d+)?[smhd](?:\\d+(?:\\.\\d+)?[smhd])*$/i'],
            'priority' => ['required', 'integer', 'min:1', 'max:8'],
            'fup_enabled' => ['nullable', 'boolean'],
            'fup_limit_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'fup_upload_speed' => ['nullable', 'string', 'max:30', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?$/'],
            'fup_download_speed' => ['nullable', 'string', 'max:30', 'regex:/^\\d+(?:\\.\\d+)?[kKmMgG]?$/'],
        ]);

        $router = Router::query()->where('enabled', true)->find($data['router_id']);
        if (!$router) {
            throw ValidationException::withMessages(['router_id' => 'Pilih router yang aktif.']);
        }

        if ($package
            && (int) $package->router_id !== (int) $router->id
            && $package->customers()->exists()) {
            throw ValidationException::withMessages([
                'router_id' => 'Paket ini masih dipakai pelanggan. Pindahkan pelanggan ke paket lain sebelum memindahkan router paket.',
            ]);
        }

        $fupEnabled = (bool) ($data['fup_enabled'] ?? false);
        $fupLimitGb = (float) ($data['fup_limit_gb'] ?? 0);
        if ($fupEnabled && ($fupLimitGb <= 0
            || blank($data['fup_upload_speed'] ?? null)
            || blank($data['fup_download_speed'] ?? null))) {
            throw ValidationException::withMessages([
                'fup_limit_gb' => 'FUP perlu batas GB lebih dari nol serta kecepatan upload dan download setelah FUP.',
            ]);
        }

        $burstEnabled = (bool) ($data['burst_enabled'] ?? false);
        if ($burstEnabled && (blank($data['burst_limit'] ?? null) || blank($data['burst_threshold'] ?? null))) {
            throw ValidationException::withMessages([
                'burst_limit' => 'Burst aktif, isi batas burst dan threshold atau matikan fitur burst.',
            ]);
        }

        $data['burst_enabled'] = $burstEnabled;
        $data['burst_time'] = $data['burst_time'] ?? '5s';
        $data['normal_speed'] = 'Upload '.$data['upload_speed'].' / Download '.$data['download_speed'];
        $data['fup_enabled'] = $fupEnabled;
        $data['fup_limit_bytes'] = $fupEnabled ? (int) round($fupLimitGb * 1073741824) : null;
        $data['fup_upload_speed'] = $fupEnabled ? ($data['fup_upload_speed'] ?? null) : null;
        $data['fup_download_speed'] = $fupEnabled ? ($data['fup_download_speed'] ?? null) : null;
        $data['priority'] = (int) $data['priority'];
        unset($data['fup_limit_gb']);

        return $data;
    }
}
