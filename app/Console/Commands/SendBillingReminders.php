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
            ->with('customer')
            ->where('status', 'unpaid')
            ->whereDate('due_date', $dueDate->toDateString())
            ->chunkById(200, function ($invoices) use ($notifications, $offset, $today, &$queued, &$failed): void {
                foreach ($invoices as $invoice) {
                    $customer = $invoice->customer;
                    $target = $customer?->whatsapp_number ?: $customer?->phone;
                    if (! $customer || ! $target) {
                        continue;
                    }

                    $amount = number_format((int) $invoice->total, 0, ',', '.');
                    $dueDate = CarbonImmutable::parse($invoice->due_date, config('billing.timezone'));
                    $paymentUrl = $invoice->payment_url ?: url('/pay/'.$invoice->public_token);
                    $serviceUsername = $customer->service_type === 'pppoe'
                        ? $customer->pppoe_username
                        : $customer->hotspot_username;
                    $message = "Yth. {$customer->name}, kami mengingatkan tagihan {$invoice->invoice_number} sebesar Rp {$amount} yang jatuh tempo pada {$dueDate->format('d-m-Y')}. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Silakan lakukan pembayaran melalui {$paymentUrl}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Terima kasih.";

                    try {
                        if ($notifications->queue($invoice, 'billing_reminder', $target, $message, [
                            'name' => $customer->name,
                            'customer_code' => $customer->customer_code,
                            'portal_username' => $customer->customer_code,
                            'service_username' => $serviceUsername,
                            'pppoe_username' => $customer->pppoe_username,
                            'portal_url' => route('portal.login'),
                            'invoice_number' => $invoice->invoice_number,
                            'amount' => $amount,
                            'due_date' => $dueDate->format('d-m-Y'),
                            'payment_url' => $paymentUrl,
                            'days_offset' => (string) $offset,
                        ], $today)) {
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

