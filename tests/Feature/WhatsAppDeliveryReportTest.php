<?php

namespace Tests\Feature;

use App\Models\BillingNotification;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WaLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fonnte_failed_response_is_logged_with_safe_reason_and_invoice_reference(): void
    {
        $invoice = $this->invoice();
        $notification = BillingNotification::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'event' => 'billing_reminder',
            'scheduled_for' => now()->toDateString(),
            'status' => 'queued',
        ]);
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_api_token' => 'test-secret-token',
            'fonnte_webhook_token' => 'test-webhook-token',
        ]);
        Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => false, 'detail' => 'Invalid target'], 200)]);

        try {
            app(\App\Services\FonnteService::class)->send($invoice->customer_id, '628123456789', 'Pengingat invoice', 'billing_reminder', [], $notification->id);
            $this->fail('Expected a failed Fonnte response to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Invalid target', $exception->getMessage());
        }

        $log = WaLog::query()->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('Invalid target', $log->response['detail']);
        $this->assertSame($notification->id, $log->billing_notification_id);
        $this->assertStringNotContainsString('test-secret-token', json_encode($log->toArray()));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.fonnte.com/send');
    }

    public function test_fonnte_delivery_webhook_updates_the_invoice_notification_status(): void
    {
        $invoice = $this->invoice();
        $notification = BillingNotification::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'event' => 'billing_reminder',
            'scheduled_for' => now()->toDateString(),
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        WaLog::query()->create([
            'customer_id' => $invoice->customer_id,
            'billing_notification_id' => $notification->id,
            'target' => '628123456789',
            'event' => 'billing_reminder',
            'message' => 'Pengingat invoice',
            'status' => 'sent',
            'provider_message_id' => 'message-id-123',
        ]);
        IntegrationSetting::query()->create(['fonnte_enabled' => true, 'fonnte_webhook_token' => 'test-webhook-token']);

        $this->postJson(route('fonnte.webhook', 'test-webhook-token'), [
            'id' => 'message-id-123',
            'status' => 'Invalid',
            'state' => 'Invalid target number',
            'stateid' => 'state-id-456',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('wa_logs', ['provider_message_id' => 'message-id-123', 'status' => 'failed', 'provider_status' => 'Invalid']);
        $this->assertDatabaseHas('billing_notifications', ['id' => $notification->id, 'status' => 'failed', 'last_error' => 'Status Fonnte: Invalid target number']);
    }

    public function test_finance_user_can_view_filtered_delivery_failures(): void
    {
        $invoice = $this->invoice();
        BillingNotification::query()->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'event' => 'billing_reminder',
            'scheduled_for' => now()->toDateString(),
            'status' => 'failed',
            'last_error' => 'Nomor WhatsApp tidak valid.',
        ]);
        $user = User::query()->create(['name' => 'Finance', 'email' => 'finance@example.test', 'password' => 'test-password', 'role' => 'finance']);

        $this->withSession(['user_id' => $user->id])
            ->get(route('invoices.notifications', ['status' => 'failed']))
            ->assertOk()
            ->assertSee('Status Pengiriman Invoice')
            ->assertSee('Nomor WhatsApp tidak valid.')
            ->assertSee('628123456789');
    }

    private function invoice(): Invoice
    {
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-DELIVERY-1',
            'name' => 'Pelanggan Pengujian',
            'whatsapp_number' => '628123456789',
            'service_type' => 'pppoe',
        ]);

        return Invoice::query()->create([
            'invoice_number' => 'INV-DELIVERY-'.uniqid(),
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now()->format('Y-m'),
            'issued_at' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'unpaid',
        ]);
    }
}

