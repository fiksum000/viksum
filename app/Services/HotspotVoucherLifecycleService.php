<?php

namespace App\Services;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use App\Models\Router;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class HotspotVoucherLifecycleService
{
    public function __construct(private RouterOsService $routerOs)
    {
    }

    /**
     * Apply first-login timestamps, expiry, and post-FUP profile changes.
     * The RouterOS on-login script records first use in the voucher comment;
     * this worker turns that marker into durable billing state.
     */
    public function maintain(): int
    {
        $handled = 0;
        $bundles = HotspotVoucher::with(['router', 'hotspotProfile'])
            ->whereIn('status', ['active', 'disabled'])
            ->whereNotNull('router_id')
            ->get()
            ->groupBy('router_id');

        foreach ($bundles as $routerId => $vouchers) {
            /** @var Router|null $router */
            $router = $vouchers->first()->router;
            if (! $router || ! $router->enabled) {
                continue;
            }

            try {
                $routerUsers = collect($this->routerOs->listHotspotUsers($router))
                    ->keyBy(fn (array $row) => (string) ($row['name'] ?? ''));

                foreach ($vouchers as $voucher) {
                    try {
                        $row = $routerUsers->get($voucher->username);
                        if (! $row) {
                            if ($voucher->sync_status !== 'missing') {
                                $voucher->update([
                                    'sync_status' => 'missing',
                                    'sync_error' => 'Akun tidak ditemukan di MikroTik saat pemeriksaan lifecycle.',
                                ]);
                            }
                            continue;
                        }

                        if ($voucher->sync_status !== 'synced') {
                            $voucher->update(['sync_status' => 'synced', 'sync_error' => null]);
                        }

                        $this->applyFirstLoginMarker($voucher, $row);
                        $this->collectUsageAndApplyFup($voucher, $router, $row);

                        if ($voucher->expires_at && $voucher->expires_at->lte(now(config('billing.timezone')))
                            && $voucher->status !== 'expired') {
                            $this->routerOs->setHotspotUserEnabled($router, $voucher->username, false);
                            $this->routerOs->disconnectHotspotActive($router, $voucher->username);
                            $voucher->update(['status' => 'expired', 'sync_error' => null]);
                        }

                        $handled++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $voucher->update([
                            'sync_status' => 'failed',
                            'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
                        ]);
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Hotspot voucher lifecycle router read failed', [
                    'router_id' => $router->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $handled;
    }

    private function applyFirstLoginMarker(HotspotVoucher $voucher, array $routerRow): void
    {
        if ($voucher->first_login_at) {
            return;
        }

        $comment = (string) ($routerRow['comment'] ?? '');
        if (! preg_match('/\|FIRST=([^|]+)/', $comment, $matches)) {
            return;
        }

        $timestamp = $this->parseRouterTimestamp(trim($matches[1]));
        if (! $timestamp) {
            Log::warning('Unrecognized Hotspot first-login timestamp', [
                'voucher_id' => $voucher->id,
                'router_id' => $voucher->router_id,
                'comment' => $comment,
            ]);
            return;
        }

        $updates = ['first_login_at' => $timestamp];
        $profile = $voucher->hotspotProfile;

        if ($profile?->starts_on_first_login && $profile->validity_value && $profile->validity_unit) {
            $expires = $this->addValidity($timestamp->copy(), $profile->validity_value, $profile->validity_unit);
            $updates['expires_at'] = $expires;
        }

        $voucher->update($updates);
        $voucher->refresh();
    }

    private function parseRouterTimestamp(string $value): ?Carbon
    {
        // RouterOS date output commonly uses either yyyy-mm-dd or mmm/dd/yyyy.
        $value = preg_replace('/^([A-Za-z]{3})\/(\d{1,2})\/(\d{4})\s+/', '$1 $2 $3 ', $value) ?? $value;
        try {
            return Carbon::parse($value, config('billing.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public function addValidity(Carbon $start, int $amount, string $unit): Carbon
    {
        return match ($unit) {
            'hour' => $start->addHours($amount),
            'day' => $start->addDays($amount),
            // Prepaid "month" is a fixed 30-day validity, similar to common voucher products.
            'month' => $start->addDays(30 * $amount),
            default => throw new \InvalidArgumentException('Unit masa berlaku tidak dikenal.'),
        };
    }

    private function collectUsageAndApplyFup(HotspotVoucher $voucher, Router $router, array $routerRow): void
    {
        $bytesIn = $routerRow['bytes-in'] ?? null;
        $bytesOut = $routerRow['bytes-out'] ?? null;
        if (! is_numeric($bytesIn) || ! is_numeric($bytesOut)) {
            return;
        }

        $bytesIn = max(0, (int) $bytesIn);
        $bytesOut = max(0, (int) $bytesOut);
        $deltaIn = $bytesIn >= (int) $voucher->last_bytes_in
            ? $bytesIn - (int) $voucher->last_bytes_in
            : $bytesIn;
        $deltaOut = $bytesOut >= (int) $voucher->last_bytes_out
            ? $bytesOut - (int) $voucher->last_bytes_out
            : $bytesOut;
        $total = (int) $voucher->total_bytes_used + $deltaIn + $deltaOut;

        $voucher->update([
            'last_bytes_in' => $bytesIn,
            'last_bytes_out' => $bytesOut,
            'total_bytes_used' => $total,
            'last_sampled_at' => now(),
        ]);
        $voucher->refresh();

        if ($voucher->status !== 'active' || $voucher->fup_applied) {
            return;
        }

        /** @var HotspotProfile|null $profile */
        $profile = $voucher->hotspotProfile;
        if (! $profile || (int) $profile->fup_limit_bytes <= 0 || $total < (int) $profile->fup_limit_bytes) {
            return;
        }

        if (blank($profile->fup_upload_speed) || blank($profile->fup_download_speed)) {
            return;
        }

        $this->routerOs->setHotspotUserProfile($router, $voucher->username, $profile->name.'-FUP');
        $this->routerOs->disconnectHotspotActive($router, $voucher->username);
        $voucher->update(['fup_applied' => true]);
    }
}
