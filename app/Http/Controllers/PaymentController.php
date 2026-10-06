<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\ActivatePaidCustomer;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController
{
    public function manual(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['amount' => 'required|integer|min:1', 'channel' => 'required|string|max:50']);
        if ((int) $data['amount'] !== (int) $invoice->total) {
            return back()->with('error', 'Nominal pembayaran harus sama dengan total invoice.');
        }

        $paid = DB::transaction(function () use ($invoice, $data): bool {
            $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($lockedInvoice->status === 'paid') {
                return false;
            }

            $lockedInvoice->update(['status' => 'paid', 'paid_at' => now()]);
            Payment::create([
                'invoice_id' => $lockedInvoice->id,
                'provider' => 'manual',
                'merchant_ref' => $lockedInvoice->invoice_number,
                'channel' => $data['channel'],
                'amount' => $data['amount'],
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            return true;
        });

        if (!$paid) {
            return back()->with('error', 'Invoice sudah lunas.');
        }

        try {
            ActivatePaidCustomer::dispatch($invoice->id);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->with('error', 'Pembayaran tercatat, tetapi aktivasi belum masuk antrean. Hubungi administrator.');
        }

        Audit::log('payment.manual_recorded', Payment::class, null, ['invoice_id' => $invoice->id, 'amount' => (int) $data['amount'], 'channel' => $data['channel']]);
        return back()->with('success', 'Pembayaran manual dicatat, notifikasi masuk antrean, dan proses unisolir dijalankan.');
    }
}
