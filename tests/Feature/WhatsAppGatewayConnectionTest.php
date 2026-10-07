<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppGatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_check_the_fonnte_device_status_without_sending_a_message(): void
    {
        $admin = $this->admin();
        $token = 'fonnte-device-token-for-test';
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_account_name' => 'FIKSUM',
            'fonnte_api_token' => $token,
            'fonnte_webhook_token' => 'webhook-token-for-test',
        ]);
        Http::fake([
            'https://api.fonnte.com/device' => Http::response([
                'status' => true,
                'device_status' => 'connect',
                'name' => 'Device FIKSUM',
                'device' => '6281234567890',
                'quota' => '88',
            ]),
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('whatsapp.index'))
            ->post(route('whatsapp.connection.check'))
            ->assertRedirect(route('whatsapp.index'))
            ->assertSessionHas('connection_check', function (array $result) use ($token): bool {
                return $result['state'] === 'connected'
                    && $result['device_name'] === 'Device FIKSUM'
                    && ! str_contains(json_encode($result), $token);
            });

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://api.fonnte.com/device'
        );
        Http::assertSentCount(1);
    }

    public function test_check_reports_disconnected_device_and_invalid_token_safely(): void
    {
        $admin = $this->admin();
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_account_name' => 'FIKSUM',
            'fonnte_api_token' => 'device-token-test',
        ]);
        Http::fake([
            'https://api.fonnte.com/device' => Http::sequence()
                ->push(['status' => true, 'device_status' => 'disconnect'], 200)
                ->push(['status' => false, 'reason' => 'token invalid'], 200),
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('whatsapp.index'))
            ->post(route('whatsapp.connection.check'))
            ->assertSessionHas('connection_check.state', 'disconnected');

        $this->withSession(['user_id' => $admin->id])
            ->from(route('whatsapp.index'))
            ->post(route('whatsapp.connection.check'))
            ->assertSessionHas('connection_check.state', 'invalid_token')
            ->assertSessionMissing('connection_check.token');
    }

    public function test_admin_can_remove_fonnte_credentials_and_disable_connection(): void
    {
        $admin = $this->admin();
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_account_name' => 'FIKSUM',
            'fonnte_api_token' => 'device-token-test',
            'fonnte_webhook_token' => 'webhook-token-test',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('whatsapp.index'))
            ->delete(route('whatsapp.connection.delete'))
            ->assertRedirect(route('whatsapp.index'))
            ->assertSessionHas('success');

        $settings = IntegrationSetting::query()->findOrFail(1);
        $this->assertFalse($settings->fonnte_enabled);
        $this->assertNull($settings->fonnte_account_name);
        $this->assertNull($settings->fonnte_api_token);
        $this->assertNull($settings->fonnte_webhook_token);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Gateway Admin',
            'email' => 'gateway-admin@example.test',
            'password' => 'test-password',
            'role' => 'admin',
        ]);
    }
}
