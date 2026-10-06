<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\TripayService;
use Illuminate\Http\Request;

class PublicPaymentController
{
    public function show(string $token, TripayService $tripay)
    {
        $invoice = Invoice::with('customer')->where('public_token', $token)->firstOrFail();
        try { $paymentChannels = $tripay->availableChannels(); } catch (\Throwable) { $paymentChannels = []; }
        return view('public.pay', compact('invoice', 'paymentChannels'));
    }

    public function create(Request $request, string $token, TripayService $tripay)
    {
        $invoice = Invoice::with('customer')->where('public_token', $token)->firstOrFail();
        if ($invoice->status !== 'unpaid') return back()->with('error', 'Invoice tidak dapat dibayar.');
        $method = $request->validate(['method' => 'required|string|max:40'])['method'];
        try {
            $tripay->assertAvailableChannel($method);
            $data = $tripay->createTransaction($method, $invoice->invoice_number, $invoice->customer->name, $invoice->customer->email ?? '', $invoice->total, $invoice->customer->phone ?? '');
            $invoice->update(['payment_url' => $data['checkout_url'] ?? null, 'payment_reference' => $data['reference'] ?? null, 'payment_expired_at' => isset($data['expired_time']) ? \Carbon\Carbon::createFromTimestamp($data['expired_time']) : null]);
            return ! empty($data['checkout_url']) ? redirect()->away($data['checkout_url']) : back()->with('error', 'Tripay tidak mengembalikan halaman checkout.');
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
