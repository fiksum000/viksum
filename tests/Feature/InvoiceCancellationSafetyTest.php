<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCancellationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_cancel_unpaid_invoice_without_payment_attempts(): void
    {
        $invoice = $this->invoice('unpaid');
        $this->loginAdmin();

        $this->post(route('invoices.cancel', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'cancelled',
            'payment_url' => null,
            'payment_reference' => null,
        ]);
    }

    public function test_invoice_with_trip ay_payment_attempt_cannot_be_cancelled(): void
    {
        $invoice = $this->invoice('unpaid');
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'tripay',
            'reference' => 'TRX-PENDING-'.bin2hex(random_bytes(5)),
            'merchant_ref' => $invoice->invoice_number,
            'channel' => 'QRIS',
            'amount' => $invoice->total,
            'status' => 'pending',
            'checkout_url' => 'https://example.test/checkout',
        ]);
        $this->loginAdmin();

        $this->post(route('invoices.cancel', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'unpaid']);
    }

    public function test_paid_invoice_cannot_be_cancelled(): void
    {
        $invoice = $this->invoice('paid');
        $this->loginAdmin();

        $this->post(route('invoices.cancel', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    }

    private function invoice(string $status): Invoice
    {
        $customer = Customer::query()->create([
            'customer_code' => 'CANCEL-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Invoice cancellation test',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
        ]);

        return Invoice::query()->create([
            'invoice_number' => 'INV-CANCEL-'.strtoupper(bin2hex(random_bytes(4))),
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => now(config('billing.timezone'))->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => $status,
            'payment_url' => 'https://example.test/pay',
            'payment_reference' => 'STALE-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function loginAdmin(): void
    {
        $user = User::query()->create([
            'name' => 'Invoice admin',
            'email' => 'invoice-cancel-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);

        $this->withSession(['user_id' => $user->id]);
    }
}
