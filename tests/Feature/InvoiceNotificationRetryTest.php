<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\BillingNotification;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvoiceNotificationRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retry_failed_reminder_to_the_customer_current_whatsapp_number(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $invoice = $this->invoice('unpaid');
        $notification = $this->notification($invoice, 'billing_reminder', 'failed', now()->toDateString());
        $this->configureFonnte();

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.notifications', ['status' => 'failed']))
            ->post(route('invoices.notifications.retry', $notification))
            ->assertRedirect(route('invoices.notifications', ['status' => 'failed']))
            ->assertSessionHas('success');

        $this->assertSame('queued', $notification->fresh()->status);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) =>
            $job->billingNotificationId === $notification->id
            && $job->customerId === $invoice->customer_id
            && $job->target === '628123450001'
            && $job->event === 'billing_reminder'
            && $job->variables['invoice_number'] === $invoice->invoice_number
            && $job->variables['payment_url'] === route('public.pay', $invoice->public_token)
        );
    }

    public function test_paid_invoice_reminder_is_not_retried_after_invoice_status_changes(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $invoice = $this->invoice('paid');
        $notification = $this->notification($invoice, 'billing_reminder', 'failed', now()->toDateString());
        $this->configureFonnte();

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.notifications', ['status' => 'failed']))
            ->post(route('invoices.notifications.retry', $notification))
            ->assertRedirect(route('invoices.notifications', ['status' => 'failed']))
            ->assertSessionHas('warning');

        Queue::assertNothingPushed();
        $this->assertSame('failed', $notification->fresh()->status);
    }

    public function test_h_minus_one_warning_can_only_be_retried_on_the_original_warning_day(): void
    {
        Queue::fake();
        $admin = $this->user('admin');
        $invoice = $this->invoice('unpaid');
        $today = now(config('billing.timezone'))->startOfDay();
        $invoice->update(['due_date' => $today->copy()->subDays(2)->toDateString()]);
        $notification = $this->notification($invoice, 'isolation_warning', 'failed', $today->toDateString());
        $this->configureFonnte();

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.notifications', ['status' => 'failed']))
            ->post(route('invoices.notifications.retry', $notification))
            ->assertRedirect(route('invoices.notifications', ['status' => 'failed']))
            ->assertSessionHas('success');

        $this->assertSame('queued', $notification->fresh()->status);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) =>
            $job->billingNotificationId === $notification->id
            && $job->event === 'isolation_warning'
            && $job->variables['isolation_date'] === $today->copy()->addDay()->format('d-m-Y')
        );
    }

    public function test_admin_can_retry_failed_payment_success_notice_once_for_a_paid_invoice(): void
    {
        Queue::fake();
        $admin = $this->user('super_admin');
        $invoice = $this->invoice('paid');
        $notification = $this->notification($invoice, 'payment_success', 'failed', '1970-01-01');
        $this->configureFonnte();

        $this->withSession(['user_id' => $admin->id])
            ->from(route('invoices.notifications', ['status' => 'failed']))
            ->post(route('invoices.notifications.retry', $notification))
            ->assertRedirect(route('invoices.notifications', ['status' => 'failed']))
            ->assertSessionHas('success');

        $this->assertSame('queued', $notification->fresh()->status);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) =>
            $job->billingNotificationId === $notification->id
            && $job->event === 'payment_success'
            && $job->variables['invoice_number'] === $invoice->invoice_number
        );
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_finance_can_view_failed_notifications_but_cannot_retry_them(): void
    {
        Queue::fake();
        $finance = $this->user('finance');
        $invoice = $this->invoice('unpaid');
        $notification = $this->notification($invoice, 'billing_reminder', 'failed', now()->toDateString());
        $this->configureFonnte();

        $this->withSession(['user_id' => $finance->id])
            ->get(route('invoices.notifications', ['status' => 'failed']))
            ->assertOk()
            ->assertDontSee(route('invoices.notifications.retry', $notification));

        $this->withSession(['user_id' => $finance->id])
            ->post(route('invoices.notifications.retry', $notification))
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame('failed', $notification->fresh()->status);
    }

    public function test_retry_button_is_shown_for_failed_invoice_notification_to_admin(): void
    {
        $admin = $this->user('admin');
        $invoice = $this->invoice('unpaid');
        $notification = $this->notification($invoice, 'billing_reminder', 'failed', now()->toDateString());

        $this->withSession(['user_id' => $admin->id])
            ->get(route('invoices.notifications', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('Kirim ulang')
            ->assertSee(route('invoices.notifications.retry', $notification));
    }

    private function notification(Invoice $invoice, string $event, string $status, string $scheduledFor): BillingNotification
    {
        return BillingNotification::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'event' => $event,
            'scheduled_for' => $scheduledFor,
            'status' => $status,
            'last_error' => 'Uji gagal pengiriman',
        ]);
    }

    private function invoice(string $status): Invoice
    {
        $customer = Customer::query()->create([
            'customer_code' => 'RETRY-WA-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Pelanggan Retry Notifikasi',
            'whatsapp_number' => '628123450001',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'grace_days' => 2,
            'is_auto_isolate' => true,
        ]);

        return Invoice::query()->create([
            'invoice_number' => 'INV-RETRY-WA-'.strtoupper(bin2hex(random_bytes(4))),
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => now(config('billing.timezone'))->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => $status,
            'paid_at' => $status === 'paid' ? now(config('billing.timezone')) : null,
        ]);
    }

    private function configureFonnte(): void
    {
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_api_token' => 'test-fonnte-token',
            'fonnte_webhook_token' => 'test-fonnte-webhook',
        ]);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' Notification Retry',
            'email' => 'notif-retry-'.$role.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => $role,
        ]);
    }
}
