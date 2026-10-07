<?php

namespace Tests\Feature;

use App\Services\TripayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TripayWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

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
