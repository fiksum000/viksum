<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_download_pdf_archive_for_a_month_and_service_type(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is required for monthly invoice archives.');
        }

        $user = User::create([
            'name' => 'Finance',
            'email' => 'finance-archive@example.test',
            'password' => 'strong-test-password',
            'role' => 'finance',
        ]);
        $package = Package::create(['name' => 'Internet 10 Mbps', 'price' => 150000, 'normal_profile' => '10M']);

        $hotspot = $this->invoiceFor('hotspot', $package, 'HSP-001');
        $this->invoiceFor('pppoe', $package, 'PPP-001');

        $response = $this->withSession(['user_id' => $user->id])
            ->get(route('invoices.archive', ['period' => '2026-10', 'service_type' => 'hotspot']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $response->assertDownload('invoice-arsip-2026-10-hotspot.zip');

        $path = $response->baseResponse->getFile()->getPathname();
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path) === true);
        $this->assertSame(1, $archive->numFiles);
        $this->assertSame($hotspot->invoice_number.'.pdf', $archive->getNameIndex(0));
        $archive->close();
    }

    private function invoiceFor(string $service, Package $package, string $code): Invoice
    {
        static $sequence = 0;
        $sequence++;

        $customer = Customer::create([
            'customer_code' => $code,
            'name' => 'Pelanggan '.$service,
            'service_type' => $service,
            'status' => 'active',
            'due_day' => 10,
            'package_id' => $package->id,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-202610-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'public_token' => str_repeat((string) $sequence, 48),
            'customer_id' => $customer->id,
            'period' => '2026-10',
            'issued_at' => '2026-10-01',
            'due_date' => '2026-10-10',
            'subtotal' => $package->price,
            'total' => $package->price,
            'status' => 'unpaid',
        ]);
        $invoice->items()->create([
            'description' => $package->name,
            'quantity' => 1,
            'unit_price' => $package->price,
            'line_total' => $package->price,
        ]);

        return $invoice;
    }
}
