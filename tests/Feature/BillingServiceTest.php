<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_invoice_follows_customer_pppoe_package_and_due_day(): void
    {
        $package = Package::create([
            'name' => 'PPPoE 20 Mbps',
            'price' => 250000,
            'normal_profile' => '20M',
        ]);

        $customer = Customer::create([
            'customer_code' => 'CUST-PPPOE-001',
            'name' => 'Pelanggan PPPoE',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 15,
            'package_id' => $package->id,
            'pppoe_username' => 'pelanggan.pppoe',
            'pppoe_profile_normal' => $package->normal_profile,
        ]);

        $billing = app(BillingService::class);

        $this->assertSame(1, $billing->generate('2026-10'));
        $this->assertSame(0, $billing->generate('2026-10'));

        $invoice = Invoice::with('items')->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('2026-10', $invoice->period);
        $this->assertSame('2026-10-15', $invoice->due_date->toDateString());
        $this->assertSame(250000, $invoice->subtotal);
        $this->assertSame(250000, $invoice->total);
        $this->assertSame('PPPoE 20 Mbps', $invoice->items->sole()->description);
        $this->assertSame(250000, $invoice->items->sole()->line_total);
    }
}

