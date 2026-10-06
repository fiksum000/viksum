<?php
namespace App\Http\Controllers;
use App\Models\Invoice; use Barryvdh\DomPDF\Facade\Pdf;
class InvoicePdfController {public function __invoke(Invoice $invoice){$invoice->load('customer.package');return Pdf::loadView('invoices.pdf',compact('invoice'))->setPaper('a4')->stream($invoice->invoice_number.'.pdf');}}
