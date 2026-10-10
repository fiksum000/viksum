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

        $result = DB::transaction(function () use ($invoice, $data): string {
            $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($lockedInvoice->status !== 'unpaid') {
                return 'status';
            }

            // Do not accept cash/manual settlement while a Tripay checkout or callback
            // could still complete; otherwise a late gateway payment could double-charge.
            if ($lockedInvoice->payments()->where('provider', 'tripay')->exists()
                || filled($lockedInvoice->payment_reference)
                || filled($lockedInvoice->payment_url)) {
                return 'tripay';
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
            return 'paid';
        });

        if ($result === 'tripay') {
            return back()->with('error', 'Invoice memiliki checkout atau riwayat Tripay. Periksa dan selesaikan transaksi gateway sebelum mencatat pembayaran manual.');
        }
        if ($result !== 'paid') {
            return back()->with('error', 'Invoice tidak berstatus belum dibayar atau sudah diproses.');
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
