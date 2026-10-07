<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use RuntimeException;
use ZipArchive;

class InvoiceArchiveController
{
    public function download(Request $request)
    {
        $filters = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'service_type' => ['nullable', 'in:pppoe,hotspot'],
        ]);

        abort_unless(class_exists(ZipArchive::class), 503, 'Fitur arsip ZIP belum tersedia di server.');

        $query = Invoice::with(['customer.package', 'items'])
            ->where('period', $filters['period'])
            ->when($filters['service_type'] ?? null, fn ($invoices, $type) => $invoices->whereHas('customer', fn ($customer) => $customer->where('service_type', $type)))
            ->orderBy('id');

        if (! $query->exists()) {
            return back()->with('error', 'Belum ada invoice untuk periode dan jenis layanan tersebut.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'billing-invoices-');
        $zip = new ZipArchive();
        $files = [];

        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            if (is_string($zipPath)) {
                @unlink($zipPath);
            }

            throw new RuntimeException('Arsip invoice tidak dapat dibuat.');
        }

        try {
            $query->chunkById(25, function ($invoices) use ($zip, &$files): void {
                foreach ($invoices as $invoice) {
                    $pdfPath = tempnam(sys_get_temp_dir(), 'billing-invoice-pdf-');
                    if ($pdfPath === false) {
                        throw new RuntimeException('File sementara invoice tidak dapat dibuat.');
                    }

                    $content = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
                        ->setPaper('a4')
                        ->output();

                    if (file_put_contents($pdfPath, $content, LOCK_EX) === false) {
                        @unlink($pdfPath);
                        throw new RuntimeException('PDF invoice tidak dapat ditulis ke arsip.');
                    }

                    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice->invoice_number).'.pdf';
                    if (! $zip->addFile($pdfPath, $filename)) {
                        @unlink($pdfPath);
                        throw new RuntimeException('PDF invoice tidak dapat ditambahkan ke arsip.');
                    }

                    $files[] = $pdfPath;
                }
            });

            if (! $zip->close()) {
                throw new RuntimeException('Arsip invoice tidak dapat diselesaikan.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            foreach ($files as $file) {
                @unlink($file);
            }
            @unlink($zipPath);
            throw $exception;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        $service = $filters['service_type'] ?? 'semua-layanan';
        $filename = "invoice-arsip-{$filters['period']}-{$service}.zip";

        return response()->download($zipPath, $filename, ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}

