<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\BillingNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class WarnIsolationCustomers extends Command
{
    protected $signature = 'billing:warn-isolation';

    protected $description = 'Antrekan pemberitahuan H-1 sebelum layanan pelanggan diisolir';

    public function handle(BillingNotificationService $notifications): int
    {
        $today = CarbonImmutable::now(config('billing.timezone'))->startOfDay();
        $queued = 0;
        $failed = 0;

        // Grace days can vary by customer, so calculate each invoice's isolation date
        // in PHP instead of relying on a database-specific DATE_ADD expression.
        Invoice::query()
            ->with('customer')
            ->where('status', 'unpaid')
            ->whereDate('due_date', '<=', $today->toDateString())
            ->whereDate('due_date', '>=', $today->subDays(366)->toDateString())
            ->chunkById(200, function ($invoices) use ($notifications, $today, &$queued, &$failed): void {
                foreach ($invoices as $invoice) {
                    $customer = $invoice->customer;
                    if (! $customer || $customer->status !== 'active' || ! $customer->is_auto_isolate) {
                        continue;
                    }

                    $graceDays = max(0, (int) ($customer->grace_days ?? config('billing.grace_days', 0)));
                    $warningDate = CarbonImmutable::parse($invoice->due_date, config('billing.timezone'))->startOfDay()->addDays($graceDays);
                    if (! $warningDate->isSameDay($today)) {
                        continue;
                    }

                    $target = $customer->whatsapp_number ?: $customer->phone;
                    if (! $target) {
                        continue;
                    }

                    $isolationDate = $warningDate->addDay();
                    $amount = number_format((int) $invoice->total, 0, ',', '.');
                    $paymentUrl = $invoice->payment_url ?: url('/pay/'.$invoice->public_token);
                    $message = "Halo {$customer->name}, tagihan {$invoice->invoice_number} sebesar Rp {$amount} belum dibayar. Layanan internet akan diisolir pada {$isolationDate->format('d-m-Y')} jika pembayaran belum diterima. Bayar: {$paymentUrl}";

                    try {
                        if ($notifications->queue($invoice, 'isolation_warning', $target, $message, [
                            'name' => $customer->name,
                            'invoice_number' => $invoice->invoice_number,
                            'amount' => $amount,
                            'due_date' => $invoice->due_date->format('d-m-Y'),
                            'isolation_date' => $isolationDate->format('d-m-Y'),
                            'payment_url' => $paymentUrl,
                        ], $today)) {
                            $queued++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->info("{$queued} peringatan H-1 masuk antrean; {$failed} gagal diantrikan.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

