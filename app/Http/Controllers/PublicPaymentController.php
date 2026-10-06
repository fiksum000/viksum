<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentSetting;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicPaymentController
{
    public function show(string $token, TripayService $tripay)
    {
        $invoice = Invoice::with('customer')->where('public_token', $token)->firstOrFail();
        try { $paymentChannels = $tripay->availableChannels(); } catch (\Throwable) { $paymentChannels = []; }
        $danaSettings = PaymentSetting::query()->first();
        $danaQrUrl = $danaSettings?->dana_enabled && $danaSettings?->dana_qr_path
            ? Storage::disk('public')->url($danaSettings->dana_qr_path)
            : null;
        $activePayment = $invoice->status === 'unpaid' && $invoice->payment_expired_at?->isFuture()
            ? $invoice->payments()->where('provider', 'tripay')->where('reference', $invoice->payment_reference)->whereIn('status', ['unpaid', 'pending'])->whereNotNull('checkout_url')->first()
            : null;
        return view('public.pay', compact('invoice', 'paymentChannels', 'activePayment', 'danaSettings', 'danaQrUrl'));
    }

    public function create(Request $request, string $token, TripayService $tripay)
    {
        $invoice = Invoice::where('public_token', $token)->firstOrFail();
        $method = $request->validate(['method' => 'required|string|max:40'])['method'];
        try {
            $payment = $tripay->createInvoiceCheckout($invoice, $method);
            return redirect()->away($payment->checkout_url);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
