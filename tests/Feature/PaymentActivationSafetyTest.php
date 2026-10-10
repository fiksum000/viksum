<?php

namespace Tests\Feature;

use App\Jobs\ActivatePaidCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use App\Services\BillingNotificationService;
use App\Services\IsolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PaymentActivationSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_payment_cannot_mark_cancelled_invoice_as_paid(): void
    {
        Queue::fake();
        $finance = User::query()->create([
            'name' => 'Finance test',
            'email' => 'finance-payment-test@example.test',
            'password' => 'strong-test-password',
            'role' => 'finance',
        ]);
        $customer = $this->customer('cancelled-invoice-customer', 'active');
        $invoice = $this->invoice($customer, 'cancelled', 'INV-CANCELLED');

        $this->withSession(['user_id' => $finance->id])
            ->from(route('invoices.index'))
            ->post(route('invoices.manual-payment', $invoice), [
                'amount' => 100000,
                'channel' => 'cash',
            ])
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('payments', ['invoice_id' => $invoice->id]);
        Queue::assertNothingPushed();
    }

    public function test_payment_activation_does_not_restore_isolated_customer_with_another_overdue_invoice(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta', 'billing.grace_days' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));
        $customer = $this->customer('has-other-overdue-invoice', 'isolated');
        $paidInvoice = $this->invoice($customer, 'paid', 'INV-PAID', '2026-09-20', '2026-09');
        $this->invoice($customer, 'unpaid', 'INV-OVERDUE', Carbon::parse('2026-10-08', 'Asia/Jakarta')->toDateString());

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldNotReceive('unisolate');
        $notifications = Mockery::mock(BillingNotificationService::class);
        $notifications->shouldReceive('recordFailure')
            ->once()
            ->withArgs(fn ($invoice, $event) => $invoice->id === $paidInvoice->id && $event === 'payment_success');

        (new ActivatePaidCustomer($paidInvoice->id))->handle($isolation, $notifications);

        $this->assertSame('isolated', $customer->fresh()->status);
    }

    public function test_payment_activation_never_unisolates_suspended_or_terminated_customers(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        foreach (['suspended', 'terminated', 'trial'] as $i => $status) {
            $customer = $this->customer('inactive-'.$i, $status);
            $invoice = $this->invoice($customer, 'paid', 'INV-INACTIVE-'.$i);
            $notifications = Mockery::mock(BillingNotificationService::class);
            $notifications->shouldReceive('recordFailure')->once();

            $isolation = Mockery::mock(IsolationService::class);
            $isolation->shouldNotReceive('unisolate');

            (new ActivatePaidCustomer($invoice->id))->handle($isolation, $notifications);
            $this->assertSame($status, $customer->fresh()->status);
        }
    }

    private function customer(string $suffix, string $status): Customer
    {
        $package = Package::query()->create([
            'name' => 'Payment test package '.$suffix,
            'price' => 100000,
            'normal_profile' => 'ppp-normal',
        ]);

        return Customer::query()->create([
            'customer_code' => 'PAY-'.strtoupper($suffix),
            'name' => 'Payment test '.$suffix,
            'service_type' => 'pppoe',
            'status' => $status,
            'due_day' => 20,
            'grace_days' => 0,
            'package_id' => $package->id,
            'pppoe_username' => 'ppp-'.$suffix,
        ]);
    }

    private function invoice(Customer $customer, string $status, string $number, ?string $dueDate = null, string $period = '2026-10'): Invoice
    {
        return Invoice::query()->create([
            'invoice_number' => $number,
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => $period,
            'issued_at' => '2026-10-01',
            'due_date' => $dueDate ?? '2026-10-20',
            'subtotal' => 100000,
            'discount' => 0,
            'penalty' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 100000,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
