<?php

namespace Tests\Feature;

use App\Jobs\ActivatePaidCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceActivationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retry_post_payment_processing_for_a_paid_invoice(): void
    {
        Queue::fake();
        $admin = $this->user('super_admin');
        $invoice = $this->invoice('paid');

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.retry-activation', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('success');

        Queue::assertPushed(ActivatePaidCustomer::class, fn (ActivatePaidCustomer $job) => $job->invoiceId === $invoice->id);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
    }

    public function test_unpaid_invoice_cannot_queue_post_payment_processing(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $invoice = $this->invoice('unpaid');

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.show', $invoice))
            ->post(route('invoices.retry-activation', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'unpaid']);
    }

    public function test_finance_role_cannot_trigger_paid_invoice_processing_retry(): void
    {
        Queue::fake();
        $finance = $this->user('finance');
        $invoice = $this->invoice('paid');

        $this->withSession(['user_id' => $finance->id])
            ->post(route('invoices.retry-activation', $invoice))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    private function invoice(string $status): Invoice
    {
        $customer = Customer::query()->create([
            'customer_code' => 'RETRY-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Activation retry customer',
            'service_type' => 'pppoe',
            'status' => 'isolated',
            'due_day' => 20,
        ]);

        return Invoice::query()->create([
            'invoice_number' => 'INV-RETRY-'.strtoupper(bin2hex(random_bytes(4))),
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => now(config('billing.timezone'))->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => $status,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::query()->create([
            'name' => ucfirst($role).' Retry Test',
            'email' => 'retry-'.$role.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => $role,
        ]);

        return $user;
    }
}
