<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\{BillingService, TripayService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Audit;

class InvoiceController
{
    public function index(Request $request)
    {
        $invoices = Invoice::with('customer')->when($request->status, fn ($query, $value) => $query->where('status', $value))->when($request->period, fn ($query, $value) => $query->where('period', $value))->latest('id')->paginate(30)->withQueryString();
        return view('invoices.index', ['invoices' => $invoices]);
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
}
