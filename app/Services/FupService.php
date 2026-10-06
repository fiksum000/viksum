<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FupState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FupService
{
    public function __construct(private RouterOsService $routerOs)
    {
    }

    public function currentPeriod(?\Illuminate\Support\Carbon $at = null): string
    {
        $date = $at ?: now(config('billing.timezone'));
        if ((int) $date->format('j') < 10) {
            $date = $date->copy()->subMonthNoOverflow();
        }

        return $date->format('Y-m');
    }

    public function collect(): int
    {
        $count = 0;
        $period = $this->currentPeriod();
        $activeMaps = [];

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
            ->chunkById(50, function ($customers) use ($period, &$count, &$activeMaps): void {
                $byRouter = [];
                foreach ($customers as $customer) {
                    $byRouter[$customer->router_id]['router'] = $customer->router;
                    $byRouter[$customer->router_id]['customers'][] = $customer;
                }

                foreach ($byRouter as $routerId => $bundle) {
                    try {
                        if (!array_key_exists($routerId, $activeMaps)) {
                            $activeMaps[$routerId] = $this->routerOs->activePppMap($bundle['router']);
                        }

                        foreach ($bundle['customers'] as $customer) {
                            $row = $activeMaps[$routerId][$customer->pppoe_username] ?? null;
                            if (!$row) {
                                continue;
                            }

                            $rx = (int) ($row['bytes-in'] ?? 0);
                            $tx = (int) ($row['bytes-out'] ?? 0);
                            $limit = (int) ($customer->fup_limit_bytes ?: $customer->package?->fup_limit_bytes ?: 0);
                            $state = FupState::firstOrCreate(['customer_id' => $customer->id, 'period' => $period]);
                            $delta = 0;
                            if ($state->last_sampled_at) {
                                $delta = ($rx >= $state->last_rx ? $rx - $state->last_rx : $rx)
                                    + ($tx >= $state->last_tx ? $tx - $state->last_tx : $tx);
                            }

                            $newTotal = $state->total_bytes + $delta;
                            $state->update(['last_rx' => $rx, 'last_tx' => $tx, 'total_bytes' => $newTotal, 'last_sampled_at' => now()]);
                            DB::table('fup_samples')->insert([
                                'customer_id' => $customer->id, 'period' => $period, 'rx_bytes' => $rx, 'tx_bytes' => $tx,
                                'delta_bytes' => $delta, 'total_bytes_after' => $newTotal, 'sampled_at' => now(),
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                            $count++;

                            $limitedProfile = $customer->fup_speed_after ?: $customer->package?->fup_speed_after;
                            if ($state->limited && ($limit <= 0 || $newTotal < $limit)) {
                                $this->restoreNormalProfile($customer);
                                $state->update(['limited' => false]);
                                DB::table('fup_logs')->insert([
                                    'customer_id' => $customer->id, 'period' => $period, 'action' => 'unlimited', 'total_bytes' => $newTotal,
                                    'profile_before' => $limitedProfile,
                                    'profile_after' => $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                                    'details' => 'Batas FUP berubah/dinonaktifkan; penggunaan masih di bawah batas baru',
                                    'created_at' => now(), 'updated_at' => now(),
                                ]);
                            }

                            if ($limit > 0 && $newTotal >= $limit && !$state->limited && $limitedProfile) {
                                $normalProfile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
                                $this->routerOs->setPppProfile($bundle['router'], $customer->pppoe_username, $limitedProfile);
                                $this->routerOs->disconnectPppActive($bundle['router'], $customer->pppoe_username);
                                $state->update(['limited' => true]);
                                DB::table('fup_logs')->insert([
                                    'customer_id' => $customer->id, 'period' => $period, 'action' => 'limited', 'total_bytes' => $newTotal,
                                    'profile_before' => $normalProfile, 'profile_after' => $limitedProfile,
                                    'details' => 'Batas FUP paket/pelanggan tercapai', 'created_at' => now(), 'updated_at' => now(),
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $count;
    }

    private function restoreDisabledCustomers(string $period): void
    {
        FupState::with(['customer.router', 'customer.package'])
            ->where('period', $period)
            ->where('limited', true)
            ->chunkById(100, function ($states): void {
                foreach ($states as $state) {
                    $customer = $state->customer;
                    if (!$customer || $this->isFupEnabled($customer)) {
                        continue;
                    }

                    try {
                        $this->restoreNormalProfile($customer);
                        $state->update(['limited' => false]);
                        DB::table('fup_logs')->insert([
                            'customer_id' => $customer->id, 'period' => $state->period, 'action' => 'restored',
                            'total_bytes' => $state->total_bytes, 'profile_before' => $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                            'profile_after' => $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                            'details' => 'FUP dinonaktifkan; profil normal dipulihkan', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
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

    public function resetMonthly(): int
    {
        $period = $this->currentPeriod();
        $affected = 0;

        FupState::with('customer.router', 'customer.package')->where('period', '!=', $period)
            ->where(function ($query): void { $query->where('limited', true)->orWhere('total_bytes', '>', 0); })
            ->chunkById(100, function ($states) use (&$affected): void {
                foreach ($states as $state) {
                    $customer = $state->customer;
                    if ($state->limited && $customer) {
                        try {
                            $this->restoreNormalProfile($customer);
                            DB::table('fup_logs')->insert([
                                'customer_id' => $customer->id, 'period' => $state->period, 'action' => 'reset',
                                'total_bytes' => $state->total_bytes,
                                'profile_before' => $customer->fup_speed_after ?: $customer->package?->fup_speed_after,
                                'profile_after' => $customer->pppoe_profile_normal ?: $customer->package?->normal_profile,
                                'details' => 'Siklus FUP tanggal 10 dimulai; profil normal dipulihkan',
                                'created_at' => now(), 'updated_at' => now(),
                            ]);
                        } catch (\Throwable $e) {
                            report($e);
                            continue;
                        }
                    }
                    // Keep the previous cycle totals for history. The new cycle starts with a fresh state row.\n                    if (!$state->limited) {\n                        $affected++;\n                    }
                }
            });

        return $affected;
    }

    private function restoreNormalProfile(Customer $customer): void
    {
        $normalProfile = $customer->pppoe_profile_normal ?: $customer->package?->normal_profile;
        if (!$customer->router || !$customer->pppoe_username || !$normalProfile) {
            throw new RuntimeException('Router, username PPPoE, atau profil normal belum dikonfigurasi.');
        }

        $this->routerOs->setPppProfile($customer->router, $customer->pppoe_username, $normalProfile);
        $this->routerOs->disconnectPppActive($customer->router, $customer->pppoe_username);
    }
}
