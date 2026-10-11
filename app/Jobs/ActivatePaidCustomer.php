<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\BillingNotificationService;
use App\Services\IsolationService;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ActivatePaidCustomer implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public array $backoff = [30, 120, 300, 600];

    public function __construct(public int $invoiceId)
    {
    }

    public function handle(IsolationService $isolation, BillingNotificationService $notifications): void
    {
        $invoice = Invoice::with('customer.router', 'customer.package')->findOrFail($this->invoiceId);
        $customer = $invoice->customer;
        if ($invoice->status !== 'paid' || !$customer) {
            return;
        }

        // Only a service isolated by billing may be restored here. Paying an old
        // invoice must never reactivate trial/suspended/terminated services.
        if ($customer->status === 'isolated' && !$this->hasOverdueUnpaidInvoice($customer->id, $customer->grace_days)) {
            $isolation->unisolate($customer);
            $customer->refresh();
        }

        if ($customer->whatsapp_number ?: $customer->phone) {
            $serviceUsername = $customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username;
            $notifications->queue(
                $invoice,
                'payment_success',
                $customer->whatsapp_number ?: $customer->phone,
                "Yth. {$customer->name}, pembayaran {$invoice->invoice_number} sebesar Rp ".number_format($invoice->total, 0, ',', '.')." telah kami terima. Terima kasih telah melakukan pembayaran. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}).",
                [
                    'name' => $customer->name,
                    'customer_code' => $customer->customer_code,
                    'portal_username' => $customer->customer_code,
                    'service_username' => $serviceUsername,
                    'pppoe_username' => $customer->pppoe_username,
                    'portal_url' => route('portal.login'),
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => number_format($invoice->total, 0, ',', '.'),
                ],
                oncePerInvoice: true,
            );
        } else {
            $notifications->recordFailure($invoice, 'payment_success', '1970-01-01', 'Nomor WhatsApp pelanggan belum diisi.');
        }

        Audit::log('payment.activation_checked', Invoice::class, $invoice->id, [
            'customer_status' => $customer->status,
        ]);
    }

    private function hasOverdueUnpaidInvoice(int $customerId, ?int $graceDays): bool
    {
        $today = CarbonImmutable::now(config('billing.timezone'))->startOfDay();
        $grace = max(0, (int) ($graceDays ?? config('billing.grace_days', 0)));

        return Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', 'unpaid')
            ->get(['id', 'due_date'])
            ->contains(function (Invoice $unpaid) use ($today, $grace): bool {
                if (!$unpaid->due_date) {
                    return false;
                }

                return CarbonImmutable::parse($unpaid->due_date, config('billing.timezone'))
                    ->startOfDay()
                    ->addDays($grace)
                    ->lt($today);
            });
    }
}
