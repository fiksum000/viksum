<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\FonnteService;
use App\Services\IsolationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class IsolateOverdueCustomers extends Command
{
    protected $signature = 'billing:isolate-overdue';
    protected $description = 'Isolir pelanggan melewati jatuh tempo dan masa tenggang';

    public function handle(IsolationService $isolation, FonnteService $wa): int
    {
        $timezone = config('billing.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $defaultGrace = max(0, (int) config('billing.grace_days', 0));
        $isolatedCount = 0;
        $notificationCount = 0;
        $failureCount = 0;

        Customer::with([
            'router',
            'package',
            'invoices' => fn ($query) => $query->where('status', 'unpaid')->whereNotNull('due_date'),
        ])
            ->where('status', 'active')
            ->where('is_auto_isolate', true)
            ->whereHas('invoices', fn ($query) => $query->where('status', 'unpaid')->whereNotNull('due_date'))
            ->chunkById(100, function ($customers) use (
                $isolation,
                $wa,
                $today,
                $timezone,
                $defaultGrace,
                &$isolatedCount,
                &$notificationCount,
                &$failureCount,
            ): void {
                foreach ($customers as $customer) {
                    $graceDays = max(0, (int) ($customer->grace_days ?? $defaultGrace));
                    $hasOverdueInvoice = $customer->invoices->contains(
                        function (Invoice $invoice) use ($today, $timezone, $graceDays): bool {
                            if (!$invoice->due_date) {
                                return false;
                            }

                            return CarbonImmutable::parse($invoice->due_date, $timezone)
                                ->startOfDay()
                                ->addDays($graceDays)
                                ->lt($today);
                        },
                    );

                    if (!$hasOverdueInvoice) {
                        continue;
                    }

                    try {
                        // The service updates billing status only after RouterOS succeeds.
                        $isolation->isolate($customer);
                        $isolatedCount++;
                    } catch (Throwable $exception) {
                        $failureCount++;
                        report($exception);
                        continue;
                    }

                    $number = $customer->whatsapp_number ?: $customer->phone;
                    if (!$number) {
                        continue;
                    }

                    $serviceUsername = $customer->service_type === 'pppoe'
                        ? $customer->pppoe_username
                        : $customer->hotspot_username;

                    try {
                        $wa->queue(
                            $customer->id,
                            $number,
                            "Yth. {$customer->name}, layanan internet Anda telah diisolir sementara karena tagihan belum dibayar. Silakan selesaikan pembayaran agar layanan dapat dipulihkan. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Terima kasih.",
                            'isolation',
                            [
                                'name' => $customer->name,
                                'customer_code' => $customer->customer_code,
                                'portal_username' => $customer->customer_code,
                                'service_username' => $serviceUsername,
                                'pppoe_username' => $customer->pppoe_username,
                                'portal_url' => route('portal.login'),
                            ],
                        );
                        $notificationCount++;
                    } catch (Throwable $exception) {
                        // A WhatsApp provider error must not undo a successful router isolation.
                        $failureCount++;
                        report($exception);
                    }
                }
            });

        $this->info("{$isolatedCount} pelanggan berhasil diisolir; {$notificationCount} notifikasi masuk antrean; {$failureCount} kegagalan perlu diperiksa.");

        return $failureCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
