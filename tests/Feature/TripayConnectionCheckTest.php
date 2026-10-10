<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TripayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TripayConnectionCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_settings_page_exposes_connection_check_button(): void
    {
        $admin = $this->loginAdmin();

        $this->withSession(['user_id' => $admin->id])
            ->get(route('payment-settings.index'))
            ->assertOk()
            ->assertSee('Tes koneksi Tripay')
            ->assertSee(route('payment-settings.check-tripay'));
    }

    public function test_tripay_connection_check_reports_available_channels_without_creating_a_transaction(): void
    {
        $admin = $this->loginAdmin();

        $tripay = Mockery::mock(TripayService::class);
        $tripay->shouldReceive('checkConnection')->once()->andReturn([
            ['code' => 'QRIS', 'name' => 'QRIS'],
            ['code' => 'BRIVA', 'name' => 'BRI Virtual Account'],
        ]);
        $this->app->instance(TripayService::class, $tripay);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('payment-settings.index'))
            ->post(route('payment-settings.check-tripay'))
            ->assertRedirect(route('payment-settings.index'))
            ->assertSessionHas('tripay_check', fn (array $result) =>
                $result['ok'] === true
                && $result['count'] === 2
                && str_contains($result['message'], '2 kanal pembayaran aktif')
            );
    }

    public function test_tripay_connection_failure_shows_safe_message_without_exposing_exception_details(): void
    {
        $admin = $this->loginAdmin();

        $tripay = Mockery::mock(TripayService::class);
        $tripay->shouldReceive('checkConnection')->once()
            ->andThrow(new RuntimeException('internal-auth-header-and-provider-details'));
        $this->app->instance(TripayService::class, $tripay);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('payment-settings.check-tripay'))
            ->assertRedirect(route('payment-settings.index'))
            ->assertSessionHas('tripay_check', fn (array $result) =>
                $result['ok'] === false
                && str_contains($result['message'], 'Tes koneksi belum berhasil')
                && ! str_contains($result['message'], 'internal-auth-header-and-provider-details')
            );
    }

    private function loginAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Tripay settings admin',
            'email' => 'tripay-settings-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);

        $this->withSession(['user_id' => $user->id]);
        return $user;
    }
}
