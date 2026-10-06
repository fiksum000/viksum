<?php

namespace App\Http\Controllers;

use App\Models\{Invoice, Payment};
use App\Services\{BillingService, TripayService};
use Illuminate\Http\Request;

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
        if ($invoice->status !== 'unpaid') return back()->with('error', 'Invoice tidak dalam status belum bayar.');
        $method = $request->validate(['method' => 'required|string|max:40'])['method'];
        try {
            $tripay->assertAvailableChannel($method);
            $data = $tripay->createTransaction($method, $invoice->invoice_number, $invoice->customer->name, $invoice->customer->email ?? '', $invoice->total, $invoice->customer->phone ?? '');
            $payment = Payment::create(['invoice_id' => $invoice->id, 'provider' => 'tripay', 'reference' => $data['reference'] ?? null, 'merchant_ref' => $invoice->invoice_number, 'channel' => $method, 'amount' => $invoice->total, 'status' => strtolower($data['status'] ?? 'unpaid'), 'checkout_url' => $data['checkout_url'] ?? null, 'raw_payload' => $data]);
            $invoice->update(['payment_url' => $data['checkout_url'] ?? null, 'payment_reference' => $payment->reference, 'payment_expired_at' => isset($data['expired_time']) ? \Carbon\Carbon::createFromTimestamp($data['expired_time']) : null]);
            return ! empty($data['checkout_url']) ? redirect()->away($data['checkout_url']) : back()->with('success', 'Transaksi Tripay dibuat, tetapi checkout URL tidak dikembalikan.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function show(Invoice $invoice, TripayService $tripay)
    {
        $invoice->load(['customer.package', 'payments', 'items']);
        try { $paymentChannels = $tripay->availableChannels(); } catch (\Throwable) { $paymentChannels = []; }
        return view('invoices.show', compact('invoice', 'paymentChannels'));
    }
}
