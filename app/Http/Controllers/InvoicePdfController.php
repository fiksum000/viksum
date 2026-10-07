<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class InvoicePdfController
{
    public function __invoke(Invoice $invoice): Response
    {
        $invoice->load(['customer.package', 'items']);

        return Pdf::loadView('invoices.pdf', compact('invoice'))
            ->setPaper('a4')
            ->stream($invoice->invoice_number.'.pdf');
    }

    public function download(Invoice $invoice): Response
    {
        $invoice->load(['customer.package', 'items']);

        return Pdf::loadView('invoices.pdf', compact('invoice'))
            ->setPaper('a4')
            ->download($invoice->invoice_number.'.pdf');
    }
}

