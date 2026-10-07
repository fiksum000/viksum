<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\BillingNotificationService;
use App\Services\IsolationService;
use App\Support\Audit;
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
        if ($invoice->status !== 'paid' || !$invoice->customer) return;

        $isolation->unisolate($invoice->customer);
        if ($invoice->customer->whatsapp_number ?: $invoice->customer->phone) {
            $customer = $invoice->customer;
            $serviceUsername = $customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username;
            $notifications->queue($invoice, 'payment_success', $customer->whatsapp_number ?: $customer->phone, "Yth. {$customer->name}, pembayaran {$invoice->invoice_number} sebesar Rp ".number_format($invoice->total, 0, ',', '.')." telah kami terima. Terima kasih telah melakukan pembayaran. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}).", [
                'name' => $customer->name,
                'customer_code' => $customer->customer_code,
                'portal_username' => $customer->customer_code,
                'service_username' => $serviceUsername,
                'pppoe_username' => $customer->pppoe_username,
                'portal_url' => route('portal.login'),
                'invoice_number' => $invoice->invoice_number,
                'amount' => number_format($invoice->total, 0, ',', '.'),
            ], oncePerInvoice: true);
        }
        Audit::log('payment.customer_activated', Invoice::class, $invoice->id);
    }
}

