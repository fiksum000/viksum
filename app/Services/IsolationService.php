<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FupState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IsolationService
{
    public function __construct(private RouterOsService $routerOs)
    {
    }

    public function isolate(Customer $customer): void
    {
        $router = $customer->router;
        if (!$router || !$router->enabled) {
            throw new RuntimeException('Router pelanggan tidak tersedia atau dinonaktifkan; isolir tidak dapat diverifikasi.');
        }

        if ($customer->service_type === 'pppoe') {
            if (blank($customer->pppoe_username)) {
                throw new RuntimeException('Username PPPoE pelanggan belum diatur; isolir dibatalkan.');
            }

            if (config('billing.isolation_method') === 'disable') {
                $this->routerOs->enablePppSecret($router, $customer->pppoe_username, false);
            } else {
                $profile = $customer->pppoe_profile_isolir ?: config('billing.isolation_profile');
                if (blank($profile)) {
                    throw new RuntimeException('Profil isolir PPP belum dikonfigurasi.');
                }
                $this->routerOs->setPppProfile($router, $customer->pppoe_username, $profile);
            }

            $this->routerOs->disconnectPppActive($router, $customer->pppoe_username);
        } elseif ($customer->service_type === 'hotspot') {
            if (blank($customer->hotspot_username)) {
                throw new RuntimeException('Username Hotspot pelanggan belum diatur; isolir dibatalkan.');
            }

            if ($customer->hotspot_profile_id) {
                $this->routerOs->setManagedHotspotUserEnabled(
                    $router,
                    $customer->hotspot_username,
                    false,
                    'Billing customer '.$customer->customer_code,
                );
            } else {
                // Accounts from earlier releases do not have billing ownership markers.
                $this->routerOs->setHotspotUserEnabled($router, $customer->hotspot_username, false);
            }
            $this->routerOs->disconnectHotspotActive($router, $customer->hotspot_username);
        } else {
            throw new RuntimeException('Jenis layanan pelanggan tidak dikenal; isolir dibatalkan.');
        }

        DB::transaction(fn () => $customer->update(['status' => 'isolated']));
    }

    public function unisolate(Customer $customer): void
    {
        // A payment for an old invoice must not silently reactivate a suspended,
        // terminated, or trial service. Only isolated customers are restored here.
        if ($customer->status !== 'isolated') {
            return;
        }

        $router = $customer->router;
        if (!$router || !$router->enabled) {
            throw new RuntimeException('Router pelanggan tidak tersedia atau dinonaktifkan; layanan tetap terisolir.');
        }

        if ($customer->service_type === 'pppoe') {
            if (blank($customer->pppoe_username)) {
                throw new RuntimeException('Username PPPoE pelanggan belum diatur; layanan tetap terisolir.');
            }

            $normalProfile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
            if (blank($normalProfile)) {
                throw new RuntimeException('Profil normal PPP belum diatur; layanan tetap terisolir.');
            }

            $state = FupState::query()
                ->where('customer_id', $customer->id)
                ->where('period', app(FupService::class)->currentPeriod())
                ->first();
            $fupEnabled = $customer->fup_override !== null
                ? (bool) $customer->fup_override
                : ((bool) $customer->fup_enabled || (bool) $customer->package?->fup_enabled);
            $fupProfile = $customer->fup_override === true && filled($customer->fup_speed_after)
                ? $customer->fup_speed_after
                : $customer->package?->fup_speed_after;
            $targetProfile = $state?->limited && $fupEnabled && filled($fupProfile)
                ? $fupProfile
                : $normalProfile;

            if (config('billing.isolation_method') === 'profile' || config('billing.isolation_method') !== 'disable') {
                $this->routerOs->setPppProfile($router, $customer->pppoe_username, $targetProfile);
            }
            $this->routerOs->enablePppSecret($router, $customer->pppoe_username, true);
        } elseif ($customer->service_type === 'hotspot') {
            if (blank($customer->hotspot_username)) {
                throw new RuntimeException('Username Hotspot pelanggan belum diatur; layanan tetap terisolir.');
            }

            if ($customer->hotspot_profile_id) {
                $this->routerOs->setManagedHotspotUserEnabled(
                    $router,
                    $customer->hotspot_username,
                    true,
                    'Billing customer '.$customer->customer_code,
                );
            } else {
                $this->routerOs->setHotspotUserEnabled($router, $customer->hotspot_username, true);
            }
        } else {
            throw new RuntimeException('Jenis layanan pelanggan tidak dikenal; layanan tetap terisolir.');
        }

        $customer->update(['status' => 'active']);
    }

    public function restoreNormalProfile(Customer $customer): void
    {
        if ($customer->service_type !== 'pppoe' || !$customer->router || !$customer->pppoe_username) {
            return;
        }
        if ($customer->status !== 'active') {
            throw new RuntimeException('Profil normal tidak dipulihkan untuk pelanggan yang tidak aktif.');
        }

        $profile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
        if (blank($profile)) {
            throw new RuntimeException('Profil normal PPP belum dikonfigurasi.');
        }
        $this->routerOs->setPppProfile($customer->router, $customer->pppoe_username, $profile);
    }
}
