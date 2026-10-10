<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\ActivatePaidCustomer;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TripayWebhookController
{
    public function __invoke(Request $request, TripayService $tripay)
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return response()->json(['success' => false, 'message' => 'Invalid JSON'], 422);
        }

        $reference = isset($data['reference']) ? (string) $data['reference'] : null;
        $merchantRef = isset($data['merchant_ref']) ? (string) $data['merchant_ref'] : null;
        $event = $request->header('X-Callback-Event');
        $signatureValid = $tripay->verifyCallbackSignature($raw, $request->header('X-Callback-Signature'));
        if (!$signatureValid) {
            // Do not persist unsigned callbacks: the payload hash is also the idempotency key,
            // so storing an invalid signature here could block a later legitimate callback.
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $hash = hash('sha256', $raw);
        DB::table('tripay_callbacks')->insertOrIgnore([
            'reference' => $reference,
            'merchant_ref' => $merchantRef,
            'event' => $event,
            'payload_hash' => $hash,
            'signature_valid' => true,
            'processing_status' => 'received',
            'processing_error' => null,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = DB::table('tripay_callbacks')->where('payload_hash', $hash)->first();
        if ($callback && !$callback->signature_valid) {
            // Heal a hash recorded by older releases before signature verification.
            DB::table('tripay_callbacks')->where('id', $callback->id)->update([
                'signature_valid' => true,
                'processing_status' => 'received',
                'processing_error' => null,
                'updated_at' => now(),
            ]);
            $callback = DB::table('tripay_callbacks')->where('payload_hash', $hash)->first();
        }

        if ($callback->processing_status === 'processed') {
            return response()->json(['success' => true, 'message' => 'Callback already processed']);
        }
        if ($event !== 'payment_status') {
            DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'rejected_event', 'processing_error' => 'Unsupported callback event', 'updated_at' => now()]);
            return response()->json(['success' => false, 'message' => 'Unsupported event'], 422);
        }

        if (!$merchantRef || !$reference) {
            DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'rejected_data', 'processing_error' => 'Missing merchant_ref or reference', 'updated_at' => now()]);
            return response()->json(['success' => false, 'message' => 'Missing payment reference'], 422);
        }

        $status = strtoupper((string) ($data['status'] ?? ''));
        if (!in_array($status, ['UNPAID', 'PAID', 'EXPIRED', 'FAILED', 'REFUND'], true)) {
            DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'rejected_status', 'processing_error' => 'Unsupported payment status', 'updated_at' => now()]);
            return response()->json(['success' => false, 'message' => 'Unsupported payment status'], 422);
        }

        $paidNow = false;
        try {
            DB::transaction(function () use ($data, $merchantRef, $reference, $status, &$paidNow): void {
                $invoice = Invoice::where('invoice_number', $merchantRef)->lockForUpdate()->first();
                if (!$invoice) {
                    throw new \RuntimeException('Invoice not found');
                }

                if ($invoice->status === 'cancelled') {
                    throw new \\RuntimeException('Invoice has been cancelled; callback requires manual reconciliation');
                }

                $amount = (int) ($data['total_amount'] ?? 0);
                if ($amount !== (int) $invoice->total) {
                    throw new \RuntimeException('Callback amount does not match invoice total');
                }

                $payment = Payment::where('reference', $reference)->lockForUpdate()->first();
                if ($payment && $payment->invoice_id !== $invoice->id) {
                    throw new \RuntimeException('Payment reference belongs to another invoice');
                }
                if (!$payment && $invoice->payment_reference && !hash_equals((string) $invoice->payment_reference, $reference)) {
                    throw new \RuntimeException('Payment reference does not match invoice');
                }

                $payment ??= new Payment();
                // A late UNPAID/EXPIRED callback must never downgrade a payment already recorded as paid.
                $effectiveStatus = $payment->status === 'paid' ? 'PAID' : $status;
                $payment->fill([
                    'invoice_id' => $invoice->id,
                    'provider' => 'tripay',
                    'reference' => $reference,
                    'merchant_ref' => $invoice->invoice_number,
                    'channel' => $data['payment_method_code'] ?? null,
                    'amount' => $amount,
                    'status' => strtolower($effectiveStatus),
                    'raw_payload' => $data,
                    'paid_at' => $effectiveStatus === 'PAID' ? ($payment->paid_at ?? now()) : $payment->paid_at,
                ]);
                $payment->save();

                if ($status === 'PAID' && $invoice->status !== 'paid') {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                    $paidNow = true;
                }
            });
        } catch (\Throwable $exception) {
            DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'rejected_data', 'processing_error' => $exception->getMessage(), 'updated_at' => now()]);
            return response()->json(['success' => false, 'message' => 'Payment validation failed'], 422);
        }

        $shouldResumeActivation = $callback->processing_status === 'activation_pending';
        if ($status === 'PAID' && ($paidNow || $shouldResumeActivation)) {
            DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'activation_pending', 'updated_at' => now()]);
            try {
                $invoice = Invoice::where('invoice_number', $merchantRef)->firstOrFail();
                ActivatePaidCustomer::dispatch($invoice->id);
            } catch (\Throwable $exception) {
                report($exception);
                DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'activation_pending', 'processing_error' => $exception->getMessage(), 'updated_at' => now()]);
                return response()->json(['success' => false, 'message' => 'Payment recorded; activation is queued for retry'], 500);
            }
        }

        DB::table('tripay_callbacks')->where('id', $callback->id)->update(['processing_status' => 'processed', 'processing_error' => null, 'processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }
}
