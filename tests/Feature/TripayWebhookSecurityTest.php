<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\TripayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TripayWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_paid_callback_cannot_reopen_cancelled_invoice(): void
    {
        $customer = Customer::query()->create([
            'customer_code' => 'CALLBACK-CANCEL-001',
            'name' => 'Cancelled invoice customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-CALLBACK-CANCEL-001',
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => now(config('billing.timezone'))->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'cancelled',
        ]);

        $payload = json_encode([
            'reference' => 'TRIPAY-LATE-CANCEL-1',
            'merchant_ref' => $invoice->invoice_number,
            'status' => 'PAID',
            'total_amount' => 100000,
        ], JSON_THROW_ON_ERROR);

        $tripay = Mockery::mock(TripayService::class);
        $tripay->shouldReceive('verifyCallbackSignature')->once()->andReturn(true);
        $this->app->instance(TripayService::class, $tripay);

        $this->call('POST', '/api/webhooks/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_EVENT' => 'payment_status',
            'HTTP_X_CALLBACK_SIGNATURE' => 'valid-signature',
        ], $payload)->assertUnprocessable();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'cancelled']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('tripay_callbacks', [
            'payload_hash' => hash('sha256', $payload),
            'processing_status' => 'rejected_data',
        ]);
    }

    public function test_invalid_signature_cannot_poison_identical_valid_callback(): void
    {
        $payload = json_encode([
            'reference' => 'TRIPAY-REF-1',
            'merchant_ref' => 'INVOICE-1',
            'status' => 'UNPAID',
            'total_amount' => 100000,
        ], JSON_THROW_ON_ERROR);

        $tripay = Mockery::mock(TripayService::class);
        $tripay->shouldReceive('verifyCallbackSignature')->twice()->andReturn(false, true);
        $this->app->instance(TripayService::class, $tripay);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_EVENT' => 'payment_status',
        ];
        $path = '/api/webhooks/tripay';

        $this->call('POST', $path, [], [], [], $headers + [
            'HTTP_X_CALLBACK_SIGNATURE' => 'invalid-signature',
        ], $payload)->assertForbidden();

        $this->assertDatabaseCount('tripay_callbacks', 0);

        $this->call('POST', $path, [], [], [], $headers + [
            'HTTP_X_CALLBACK_SIGNATURE' => 'valid-signature',
        ], $payload)->assertUnprocessable();

        $this->assertDatabaseHas('tripay_callbacks', [
            'payload_hash' => hash('sha256', $payload),
            'signature_valid' => 1,
            'processing_status' => 'rejected_data',
        ]);
    }
}
