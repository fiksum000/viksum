<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\BillingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class SendBillingReminders extends Command
{
    protected $signature = 'billing:reminders {offset=0}';

    protected $description = 'Antrekan pengingat WhatsApp untuk invoice berdasarkan tanggal jatuh tempo';

    public function handle(BillingNotificationService $notifications): int
    {
        $offset = (int) $this->argument('offset');
        $today = CarbonImmutable::now(config('billing.timezone'))->startOfDay();
        $dueDate = $today->addDays($offset);
        $queued = 0;
        $failed = 0;

        Invoice::query()
            ->with(['customer.package', 'customer.hotspotProfile'])
            ->where('status', 'unpaid')
            ->whereDate('due_date', $dueDate->toDateString())
            ->chunkById(200, function ($invoices) use ($notifications, $offset, $today, &$queued, &$failed): void {
                foreach ($invoices as $invoice) {
                    $customer = $invoice->customer;
                    $target = $customer?->whatsapp_number ?: $customer?->phone;
                    if (! $customer || ! $target) {
                        $notifications->recordFailure($invoice, 'billing_reminder', $today, 'Nomor WhatsApp pelanggan belum diisi.');
                        $failed++;
                        continue;
                    }

                    [$message, $variables] = $notifications->billingReminderPayload($invoice, $customer, (string) $offset);

                    try {
                        if ($notifications->queue($invoice, 'billing_reminder', $target, $message, $variables, $today)) {
                            $queued++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->info("{$queued} pengingat tagihan masuk antrean; {$failed} gagal diantrikan.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

