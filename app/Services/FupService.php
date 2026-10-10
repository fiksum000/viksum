<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FupState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FupService
{
    public function __construct(private RouterOsService $routerOs)
    {
    }

    /** Billing/FUP period rolls over on the 10th of each month. */
    public function currentPeriod(): string
    {
        $now = now(config('billing.timezone'));

        if ($now->day < 10) {
            $now->subMonthNoOverflow();
        }

        return $now->format('Y-m');
    }

    public function collect(): int
    {
        $count = 0;
        $period = $this->currentPeriod();
        $activeMaps = [];
        $secretMaps = [];

        // Recover a previous month's limited sessions as soon as the collection worker
        // runs after rollover, rather than depending only on the separate reset schedule.
        $this->restoreExpiredPeriodLimits($period);
        $this->restoreDisabledCustomers($period);

        Customer::with(['router', 'package'])
            ->where('status', 'active')
            ->where('service_type', 'pppoe')
            ->whereNotNull('pppoe_username')
            ->whereNotNull('router_id')
            ->where(function ($query): void {
                $query->where('fup_override', true)
                    ->orWhere(function ($inherit): void {
                        $inherit->whereNull('fup_override')
                            ->where(function ($enabled): void {
                                $enabled->where('fup_enabled', true)
                                    ->orWhereHas('package', fn ($package) => $package->where('fup_enabled', true));
                            });
                    });
            })
            ->chunkById(50, function ($customers) use ($period, &$count, &$activeMaps, &$secretMaps): void {
                $byRouter = [];
                foreach ($customers as $customer) {
                    $byRouter[$customer->router_id]['router'] = $customer->router;
                    $byRouter[$customer->router_id]['customers'][] = $customer;
                }

                foreach ($byRouter as $routerId => $bundle) {
                    $router = $bundle['router'];
                    if (!$router || !$router->enabled) {
                        continue;
                    }

                    try {
                        // Fetch the active PPP table once per router per collection run,
                        // even when many subscribers span multiple database chunks.
                        if (!array_key_exists($routerId, $activeMaps)) {
                            $activeMaps[$routerId] = $this->routerOs->activePppMap($router);
                        }
                        $activeMap = $activeMaps[$routerId];

                        if (!array_key_exists($routerId, $secretMaps)) {
                            try {
                                $secretMaps[$routerId] = collect($this->routerOs->listPppSecrets($router))
                                    ->keyBy(fn (array $secret) => (string) ($secret['name'] ?? ''));
                            } catch (Throwable $exception) {
                                report($exception);
                                $secretMaps[$routerId] = collect();
                            }
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        continue;
                    }

                    foreach ($bundle['customers'] as $customer) {
                        try {
                            $row = $activeMap[$customer->pppoe_username] ?? null;
                            if (!$row) {
                                continue;
                            }

                            $rx = max(0, (int) ($row['bytes-in'] ?? 0));
                            $tx = max(0, (int) ($row['bytes-out'] ?? 0));
                            $sessionId = filled($row['.id'] ?? null) ? (string) $row['.id'] : null;
                            $state = FupState::firstOrCreate([
                                'customer_id' => $customer->id,
                                'period' => $period,
                            ]);

                            $sessionChanged = $sessionId !== null
                                && filled($state->last_session_id)
                                && $state->last_session_id !== $sessionId;

                            // PPP counters reset when a session is recreated. When the API
                            // returns a different active-session ID, count the new session's
                            // current counters, even if they happen to exceed the previous ones.
                            // First observation establishes a baseline to avoid counting traffic
                            // from before this package/customer's FUP tracking began.
                            if (!$state->last_sampled_at) {
                                $delta = 0;
                            } elseif ($sessionChanged) {
                                $delta = $rx + $tx;
                            } else {
                                $delta = ($rx >= (int) $state->last_rx ? $rx - (int) $state->last_rx : $rx)
                                    + ($tx >= (int) $state->last_tx ? $tx - (int) $state->last_tx : $tx);
                            }

                            $newTotal = (int) $state->total_bytes + $delta;
                            $state->update([
                                'last_rx' => $rx,
                                'last_tx' => $tx,
                                'total_bytes' => $newTotal,
                                'last_sampled_at' => now(),
                                'last_session_id' => $sessionId,
                            ]);

                            DB::table('fup_samples')->insert([
                                'customer_id' => $customer->id,
                                'period' => $period,
                                'rx_bytes' => $rx,
                                'tx_bytes' => $tx,
                                'delta_bytes' => $delta,
                                'total_bytes_after' => $newTotal,
                                'sampled_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $count++;

                            $fupEnabled = $this->isFupEnabled($customer);
                            $limit = $this->effectiveLimit($customer);
                            $limitedProfile = $this->limitedProfile($customer);

                            if ($state->limited
                                && (!$fupEnabled || $limit <= 0 || blank($limitedProfile) || $newTotal < $limit)) {
                                $this->restoreNormalProfile($customer);
                                $state->update(['limited' => false]);
                                $this->logFup(
                                    $customer,
                                    $period,
                                    'unlimited',
                                    $newTotal,
                                    $limitedProfile,
                                    $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                                    'FUP dinonaktifkan, batas berubah, atau penggunaan berada di bawah batas baru; profil normal dipulihkan',
                                );
                            }

                            if ($fupEnabled && $limit > 0 && $newTotal >= $limit
                                && !$state->limited && filled($limitedProfile)) {
                                $normalProfile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
                                $this->routerOs->setPppProfile($router, $customer->pppoe_username, $limitedProfile);
                                $this->routerOs->disconnectPppActive($router, $customer->pppoe_username);
                                $state->update(['limited' => true]);
                                $this->logFup(
                                    $customer,
                                    $period,
                                    'limited',
                                    $newTotal,
                                    $normalProfile,
                                    $limitedProfile,
                                    'Batas FUP paket/pelanggan tercapai',
                                );
                            }

                            // Re-apply the FUP profile if another workflow (for example
                            // unisolation) returned the secret to normal while FUP is still due.
                            if ($state->limited && $fupEnabled && $limit > 0
                                && $newTotal >= $limit && filled($limitedProfile)) {
                                $currentSecret = $secretMaps[$routerId]->get($customer->pppoe_username);
                                if (($currentSecret['profile'] ?? null) !== $limitedProfile) {
                                    $this->routerOs->setPppProfile($router, $customer->pppoe_username, $limitedProfile);
                                    $this->routerOs->disconnectPppActive($router, $customer->pppoe_username);
                                }
                            }
                        } catch (Throwable $exception) {
                            // A problem with one secret must not stop FUP for the rest of the router.
                            report($exception);
                        }
                    }
                }
            });

        return $count;
    }

    private function restoreExpiredPeriodLimits(string $period): void
    {
        FupState::with(['customer.router', 'customer.package'])
            ->where('period', '!=', $period)
            ->where('limited', true)
            ->chunkById(100, function ($states): void {
                foreach ($states as $state) {
                    $customer = $state->customer;
                    if (!$customer) {
                        $state->update([
                            'total_bytes' => 0,
                            'last_rx' => 0,
                            'last_tx' => 0,
                            'limited' => false,
                            'last_session_id' => null,
                        ]);
                        continue;
                    }

                    try {
                        if ($customer->status === 'active') {
                            $this->restoreNormalProfile($customer);
                            $this->logFup(
                                $customer,
                                $state->period,
                                'reset',
                                (int) $state->total_bytes,
                                $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                                $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                                'Periode FUP selesai; profil normal dipulihkan',
                            );
                        } else {
                            // Do not replace an isolation profile with the normal profile.
                            $this->logFup(
                                $customer,
                                $state->period,
                                'reset',
                                (int) $state->total_bytes,
                                $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                                null,
                                'Periode FUP selesai; akun tidak aktif sehingga profil router tidak disentuh',
                            );
                        }

                        $state->update([
                            'total_bytes' => 0,
                            'last_rx' => 0,
                            'last_tx' => 0,
                            'limited' => false,
                            'last_sampled_at' => null,
                            'last_session_id' => null,
                        ]);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }

    private function restoreDisabledCustomers(string $period): void
    {
        FupState::with(['customer.router', 'customer.package'])
            ->where('period', $period)
            ->where('limited', true)
            ->chunkById(100, function ($states): void {
                foreach ($states as $state) {
                    $customer = $state->customer;
                    if (!$customer) {
                        continue;
                    }

                    // Preserve the limited state while the service is isolated,
                    // suspended, or terminated so a later authorized restore can keep FUP.
                    // Never touch the router profile for an inactive service.
                    if ($customer->status !== 'active') {
                        continue;
                    }

                    if ($this->isFupEnabled($customer)) {
                        continue;
                    }

                    try {
                        $this->restoreNormalProfile($customer);
                        $state->update(['limited' => false]);
                        $this->logFup(
                            $customer,
                            $state->period,
                            'restored',
                            (int) $state->total_bytes,
                            $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                            $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                            'FUP dinonaktifkan; profil normal dipulihkan',
                        );
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }

    private function isFupEnabled(Customer $customer): bool
    {
        if ($customer->fup_override !== null) {
            return (bool) $customer->fup_override;
        }

        return (bool) $customer->fup_enabled || (bool) $customer->package?->fup_enabled;
    }

    private function effectiveLimit(Customer $customer): int
    {
        return max(0, (int) ($customer->fup_limit_bytes ?: $customer->package?->fup_limit_bytes ?: 0));
    }

    private function limitedProfile(Customer $customer): ?string
    {
        if (filled($customer->fup_speed_after)
            && ($customer->fup_override === true || ($customer->fup_override === null && $customer->fup_enabled))) {
            return $customer->fup_speed_after;
        }

        return filled($customer->package?->fup_speed_after)
            ? $customer->package->fup_speed_after
            : null;
    }

    public function resetMonthly(): int
    {
        $period = $this->currentPeriod();
        $affected = 0;

        FupState::with(['customer.router', 'customer.package'])
            ->where('period', '!=', $period)
            ->where(function ($query): void {
                $query->where('limited', true)->orWhere('total_bytes', '>', 0);
            })
            ->chunkById(100, function ($states) use (&$affected): void {
                foreach ($states as $state) {
                    $customer = $state->customer;

                    if ($state->limited && $customer) {
                        try {
                            if ($customer->status === 'active') {
                                $this->restoreNormalProfile($customer);
                            }

                            $this->logFup(
                                $customer,
                                $state->period,
                                'reset',
                                (int) $state->total_bytes,
                                $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                                $customer->status === 'active'
                                    ? ($customer->pppoe_profile_normal ?: $customer->package?->normal_profile)
                                    : null,
                                $customer->status === 'active'
                                    ? 'Kuota FUP direset; profil normal dipulihkan'
                                    : 'Kuota FUP direset; profil router tidak disentuh karena layanan tidak aktif',
                            );
                        } catch (Throwable $exception) {
                            report($exception);
                            continue;
                        }
                    }

                    $state->update([
                        'total_bytes' => 0,
                        'last_rx' => 0,
                        'last_tx' => 0,
                        'limited' => false,
                        'last_sampled_at' => null,
                        'last_session_id' => null,
                    ]);
                    $affected++;
                }
            });

        return $affected;
    }

    private function restoreNormalProfile(Customer $customer): void
    {
        if ($customer->status !== 'active') {
            throw new RuntimeException('Profil normal tidak dipulihkan karena status layanan pelanggan bukan aktif.');
        }

        $normalProfile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
        if (!$customer->router || !$customer->pppoe_username || !$normalProfile) {
            throw new RuntimeException('Router, username PPPoE, atau profil normal belum dikonfigurasi.');
        }

        $this->routerOs->setPppProfile($customer->router, $customer->pppoe_username, $normalProfile);
        $this->routerOs->disconnectPppActive($customer->router, $customer->pppoe_username);
    }

    private function logFup(
        Customer $customer,
        string $period,
        string $action,
        int $totalBytes,
        ?string $profileBefore,
        ?string $profileAfter,
        string $details,
    ): void {
        DB::table('fup_logs')->insert([
            'customer_id' => $customer->id,
            'period' => $period,
            'action' => $action,
            'total_bytes' => $totalBytes,
            'profile_before' => $profileBefore,
            'profile_after' => $profileAfter,
            'details' => $details,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
