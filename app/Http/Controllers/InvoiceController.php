<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\{BillingNotificationService, BillingService, TripayService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Audit;

class InvoiceController
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,unpaid,paid,cancelled,expired'],
            'period' => ['nullable', 'date_format:Y-m'],
            'service_type' => ['nullable', 'in:pppoe,hotspot'],
        ]);

        $invoices = Invoice::with('customer')
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['period'] ?? null, fn ($query, $value) => $query->where('period', $value))
            ->when($filters['service_type'] ?? null, fn ($query, $value) => $query->whereHas('customer', fn ($customer) => $customer->where('service_type', $value)))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'archivePeriod' => $filters['period'] ?? now(config('billing.timezone'))->format('Y-m'),
        ]);
    }

    public function generate(Request $request, BillingService $billing)
    {
        $period = $request->input('period', now(config('billing.timezone'))->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) return back()->with('error', 'Format periode tidak valid.');
        $count = $billing->generate($period);
        return back()->with('success', "{$count} invoice dibuat untuk {$period}.");
    }

    public function pay(Request $request, Invoice $invoice, TripayService $tripay)
    {
        $method = $request->validate(['method' => 'required|string|max:40'])['method'];
        try {
            $payment = $tripay->createInvoiceCheckout($invoice, $method);
            return redirect()->away($payment->checkout_url);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Pembayaran belum dapat dibuat. Silakan coba lagi atau hubungi administrator.');
        }
    }

    public function show(Invoice $invoice, TripayService $tripay)
    {
        $invoice->load(['customer.package', 'payments', 'items']);
        try { $paymentChannels = $tripay->availableChannels(); } catch (\Throwable) { $paymentChannels = []; }
        $activePayment = $invoice->status === 'unpaid' && $invoice->payment_expired_at?->isFuture()
            ? $invoice->payments->first(fn ($payment) => $payment->provider === 'tripay' && $payment->reference === $invoice->payment_reference && in_array($payment->status, ['unpaid', 'pending'], true) && $payment->checkout_url)
            : null;
        return view('invoices.show', compact('invoice', 'paymentChannels', 'activePayment'));
    }

    public function cancel(Invoice $invoice)
    {
        $result = DB::transaction(function () use ($invoice): string {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (!in_array($lockedInvoice->status, ['draft', 'unpaid'], true)) {
                return 'status';
            }

            // A pending or completed payment attempt may still settle at the gateway.
            // Do not cancel an invoice once any payment record exists.
            if ($lockedInvoice->payments()->exists()) {
                return 'payments';
            }

            $lockedInvoice->update([
                'status' => 'cancelled',
                'payment_url' => null,
                'payment_reference' => null,
                'payment_expired_at' => now(),
            ]);

            return 'cancelled';
        });

        if ($result === 'status') {
            return back()->with('error', 'Hanya invoice draft atau belum lunas yang dapat dibatalkan.');
        }
        if ($result === 'payments') {
            return back()->with('error', 'Invoice memiliki riwayat percobaan pembayaran. Selesaikan atau periksa transaksi terlebih dahulu; invoice tidak dibatalkan otomatis.');
        }

        Audit::log('invoice.cancelled', Invoice::class, $invoice->id, [
            'invoice_number' => $invoice->invoice_number,
        ]);

        return back()->with('success', 'Invoice dibatalkan. Riwayat dan data pelanggan tetap tersimpan.');
    }

    public function adjust(Request $request, Invoice $invoice)
    {
        $amounts = $request->validate(['discount' => 'required|integer|min:0', 'penalty' => 'required|integer|min:0']);
        if ($invoice->status !== 'unpaid') return back()->with('error', 'Hanya invoice belum lunas yang dapat disesuaikan.');
        if ($invoice->payments()->where('provider', 'tripay')->exists()) return back()->with('error', 'Invoice sudah memiliki transaksi Tripay. Buat penyesuaian sebelum membuat checkout baru.');
        if ($amounts['discount'] > $invoice->subtotal) return back()->with('error', 'Diskon tidak boleh melebihi subtotal.');

        $updated = DB::transaction(function () use ($invoice, $amounts): bool {
            $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($lockedInvoice->status !== 'unpaid' || $amounts['discount'] > $lockedInvoice->subtotal || $lockedInvoice->payments()->where('provider', 'tripay')->exists()) return false;
            $lockedInvoice->update($amounts + ['total' => $lockedInvoice->subtotal - $amounts['discount'] + $lockedInvoice->tax_amount + $amounts['penalty']]);
            return true;
        });
        if (! $updated) return back()->with('error', 'Status invoice berubah; muat ulang halaman sebelum menyesuaikan.');

        Audit::log('invoice.adjusted', Invoice::class, $invoice->id, $amounts);
        return back()->with('success', 'Diskon dan denda invoice diperbarui.');
    }

    public function remind(Request $request, Invoice $invoice, BillingNotificationService $notifications)
    {
        if ($invoice->status !== 'unpaid') {
            return back()->with('error', 'Pengingat hanya dapat dikirim untuk invoice yang belum lunas.');
        }

        $invoice->loadMissing('customer');
        $target = $invoice->customer?->whatsapp_number ?: $invoice->customer?->phone;
        if (! $target) {
            $notifications->recordFailure($invoice, 'billing_reminder', now(config('billing.timezone')), 'Nomor WhatsApp pelanggan belum diisi.');
            return back()->with('error', 'Nomor WhatsApp pelanggan belum diisi.');
        }

        $amount = number_format((int) $invoice->total, 0, ',', '.');
        $dueDate = $invoice->due_date->format('d-m-Y');
        $paymentUrl = $invoice->payment_url ?: route('public.pay', $invoice->public_token);
        $customer = $invoice->customer;
        $serviceUsername = $customer->service_type === 'pppoe' ? $customer->pppoe_username : $customer->hotspot_username;

        try {
            $queued = $notifications->queue($invoice, 'billing_reminder', $target,
                "Yth. {$customer->name}, kami mengingatkan tagihan {$invoice->invoice_number} sebesar Rp {$amount} yang jatuh tempo pada {$dueDate}. ID pelanggan: {$customer->customer_code}. Username layanan: {$serviceUsername}. Silakan bayar melalui {$paymentUrl}. Portal pelanggan: ".route('portal.login')." (ID login: {$customer->customer_code}). Terima kasih.",
                [
                    'name' => $customer->name,
                    'customer_code' => $customer->customer_code,
                    'portal_username' => $customer->customer_code,
                    'service_username' => $serviceUsername,
                    'pppoe_username' => $customer->pppoe_username,
                    'portal_url' => route('portal.login'),
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $amount,
                    'due_date' => $dueDate,
                    'payment_url' => $paymentUrl,
                    'days_offset' => 'manual',
                ],
                now(config('billing.timezone')),
            );
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Pengingat gagal masuk antrean. Periksa antrean WhatsApp dan coba lagi nanti.');
        }

        if (! $queued) {
            return back()->with('error', 'Pengingat untuk invoice ini sudah masuk antrean hari ini.');
        }

        Audit::log('invoice.reminder_queued', Invoice::class, $invoice->id);
        return back()->with('success', 'Pengingat tagihan masuk antrean WhatsApp.');
    }
}

