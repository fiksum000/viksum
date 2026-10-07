<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\BillingNotification;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use App\Services\BillingNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingWhatsAppNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_date_reminders_are_queued_for_all_matching_customers_once_per_day(): void
    {
        Queue::fake();
        $this->travelTo(now(config('billing.timezone'))->startOfDay());
        $firstCustomer = $this->customer('628111111111', 5);
        $firstCustomer->update(['pppoe_username' => 'ppp-first']);
        $first = $this->invoiceFor($firstCustomer, now(config('billing.timezone'))->addDay());
        $second = $this->invoiceFor($this->customer('628222222222', 5), now(config('billing.timezone'))->addDay());

        $this->artisan('billing:reminders', ['offset' => 1])->assertSuccessful();
        $this->artisan('billing:reminders', ['offset' => 1])->assertSuccessful();

        Queue::assertPushedTimes(SendWhatsAppMessage::class, 2);
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) =>
            $job->customerId === $firstCustomer->id
            && $job->variables['customer_code'] === $firstCustomer->customer_code
            && $job->variables['portal_username'] === $firstCustomer->customer_code
            && $job->variables['pppoe_username'] === 'ppp-first'
            && $job->variables['portal_url'] === route('portal.login')
        );
        $this->assertDatabaseCount('billing_notifications', 2);
        $this->assertDatabaseHas('billing_notifications', ['invoice_id' => $first->id, 'event' => 'billing_reminder']);
        $this->assertDatabaseHas('billing_notifications', ['invoice_id' => $second->id, 'event' => 'billing_reminder']);
    }

    public function test_isolation_warning_is_queued_on_the_day_before_effective_isolation(): void
    {
        Queue::fake();
        $this->travelTo(now(config('billing.timezone'))->startOfDay());
        $customer = $this->customer('628333333333', 2);
        $invoice = $this->invoiceFor($customer, now(config('billing.timezone'))->subDays(2));

        $this->artisan('billing:warn-isolation')->assertSuccessful();
        $this->artisan('billing:warn-isolation')->assertSuccessful();

        Queue::assertPushedTimes(SendWhatsAppMessage::class, 1);
        $this->assertTrue(BillingNotification::query()
            ->where('invoice_id', $invoice->id)
            ->where('event', 'isolation_warning')
            ->whereDate('scheduled_for', now(config('billing.timezone'))->toDateString())
            ->exists());
    }

    public function test_finance_user_can_queue_one_invoice_reminder_without_sending_to_other_customers(): void
    {
        Queue::fake();
        $user = User::create(['name' => 'Finance', 'email' => 'finance@example.test', 'password' => 'strong-test-password', 'role' => 'finance']);
        $invoice = $this->invoiceFor($this->customer('628444444444', 5), now(config('billing.timezone'))->addDays(5));
        $other = $this->invoiceFor($this->customer('628555555555', 5), now(config('billing.timezone'))->addDays(5));

        $this->withSession(['user_id' => $user->id])
            ->post(route('invoices.remind', $invoice))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushedTimes(SendWhatsAppMessage::class, 1);
        $this->assertDatabaseHas('billing_notifications', ['invoice_id' => $invoice->id, 'event' => 'billing_reminder']);
        $this->assertDatabaseMissing('billing_notifications', ['invoice_id' => $other->id, 'event' => 'billing_reminder']);
    }

    public function test_payment_success_notice_is_only_queued_once_per_invoice(): void
    {
        Queue::fake();
        $invoice = $this->invoiceFor($this->customer('628666666666', 5), now(config('billing.timezone')));
        $notifications = app(BillingNotificationService::class);
        $payload = [$invoice, 'payment_success', '628666666666', 'Pembayaran diterima.', ['name' => 'Pelanggan']];

        $this->assertTrue($notifications->queue($invoice, 'payment_success', '628666666666', 'Pembayaran diterima.', ['name' => 'Pelanggan'], oncePerInvoice: true));
        $this->assertFalse($notifications->queue($invoice, 'payment_success', '628666666666', 'Pembayaran diterima.', ['name' => 'Pelanggan'], oncePerInvoice: true));
        Queue::assertPushedTimes(SendWhatsAppMessage::class, 1);
    }

    public function test_online_registration_rejects_missing_address_and_location_fields(): void
    {
        Package::create(['name' => '10 Mbps', 'price' => 150000, 'normal_profile' => '10M']);

        $this->from(route('register.create'))
            ->post(route('register.store'), [
                'name' => 'Pemohon Uji',
                'whatsapp_number' => '081234567890',
                'area' => 'Area Uji',
                'package_id' => 1,
                'address' => 'Jalan Uji nomor 1',
                'terms' => '1',
            ])
            ->assertRedirect(route('register.create'))
            ->assertSessionHasErrors(['village', 'district', 'city', 'latitude', 'longitude']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_online_registration_accepts_complete_required_address_and_coordinates(): void
    {
        Package::create(['name' => '10 Mbps', 'price' => 150000, 'normal_profile' => '10M']);

        $this->post(route('register.store'), [
            'name' => 'Pemohon Lengkap',
            'whatsapp_number' => '081234567890',
            'area' => 'Area Uji',
            'package_id' => 1,
            'address' => 'Jalan Uji nomor 1',
            'village' => 'Desa Uji',
            'district' => 'Kecamatan Uji',
            'city' => 'Kota Uji',
            'latitude' => '-6.2',
            'longitude' => '106.8',
            'terms' => '1',
        ])->assertRedirect(route('register.complete'));

        $this->assertDatabaseHas('customers', [
            'name' => 'Pemohon Lengkap',
            'village' => 'Desa Uji',
            'district' => 'Kecamatan Uji',
            'city' => 'Kota Uji',
            'latitude' => -6.2,
            'longitude' => 106.8,
        ]);
    }

    private function customer(string $whatsapp, int $graceDays): Customer
    {
        return Customer::create([
            'customer_code' => 'C'.fake()->unique()->numerify('######'),
            'name' => 'Pelanggan '.substr($whatsapp, -4),
            'whatsapp_number' => $whatsapp,
            'status' => 'active',
            'service_type' => 'pppoe',
            'due_day' => 10,
            'grace_days' => $graceDays,
            'is_auto_isolate' => true,
        ]);
    }

    private function invoiceFor(Customer $customer, $dueDate): Invoice
    {
        static $sequence = 0;
        $sequence++;

        return Invoice::create([
            'invoice_number' => 'INV-TEST-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'public_token' => str_repeat((string) $sequence, 48),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'unpaid',
        ]);
    }
}
