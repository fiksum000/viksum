<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HotspotVoucherLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_voucher_disconnects_its_live_session_before_updating_billing_status(): void
    {
        $router = $this->router();
        $voucher = $this->voucher($router, 'wifi-disable');
        $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('setHotspotUserEnabled')
            ->once()->ordered()
            ->withArgs(fn ($actualRouter, $username, $enabled) =>
                $actualRouter->id === $router->id && $username === $voucher->username && $enabled === false);
        $routerOs->shouldReceive('disconnectHotspotActive')
            ->once()->ordered()
            ->withArgs(fn ($actualRouter, $username) =>
                $actualRouter->id === $router->id && $username === $voucher->username);
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => User::where('email', 'admin@example.test')->value('id')])
            ->patch(route('hotspot.toggle', $voucher))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('hotspot_vouchers', ['id' => $voucher->id, 'status' => 'disabled']);
    }

    public function test_deleting_voucher_disconnects_the_session_before_removing_router_and_database_account(): void
    {
        $router = $this->router();
        $voucher = $this->voucher($router, 'wifi-delete');
        $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('disconnectHotspotActive')
            ->once()->ordered()
            ->withArgs(fn ($actualRouter, $username) =>
                $actualRouter->id === $router->id && $username === $voucher->username);
        $routerOs->shouldReceive('deleteHotspotUser')
            ->once()->ordered()
            ->withArgs(fn ($actualRouter, $username) =>
                $actualRouter->id === $router->id && $username === $voucher->username);
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => User::where('email', 'admin@example.test')->value('id')])
            ->delete(route('hotspot.destroy', $voucher))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('hotspot_vouchers', ['id' => $voucher->id]);
    }

    public function test_generator_rejects_a_profile_missing_from_router_before_creating_any_vouchers(): void
    {
        $router = $this->router();
        $admin = $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('assertHotspotProfileExists')
            ->once()
            ->withArgs(fn ($actualRouter, $profile) => $actualRouter->id === $router->id && $profile === 'missing-profile')
            ->andThrow(new \RuntimeException('profile not found'));
        $routerOs->shouldReceive('createHotspotUser')->never();
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('hotspot.generate'), [
                'router_id' => $router->id,
                'profile' => 'missing-profile',
                'quantity' => 3,
                'prefix' => 'WIFI',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('hotspot_vouchers', 0);
    }

    public function test_generator_validates_profile_once_before_creating_the_requested_batch(): void
    {
        $router = $this->router();
        $admin = $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('assertHotspotProfileExists')
            ->once()
            ->withArgs(fn ($actualRouter, $profile) => $actualRouter->id === $router->id && $profile === 'hs-normal');
        $routerOs->shouldReceive('createHotspotUser')
            ->twice()
            ->withArgs(fn ($actualRouter, $username, $password, $profile, $comment, $validated) =>
                $actualRouter->id === $router->id
                && str_starts_with($username, 'WIFI')
                && strlen($password) === 8
                && $profile === 'hs-normal'
                && $comment === 'Billing voucher'
                && $validated === true);
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('hotspot.generate'), [
                'router_id' => $router->id,
                'profile' => 'hs-normal',
                'quantity' => 2,
                'prefix' => 'WIFI',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('hotspot_vouchers', 2);
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Hotspot test router',
            'host' => '192.0.2.40',
            'port' => 8728,
            'username' => 'billing-hotspot-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
    }

    private function voucher(Router $router, string $username): HotspotVoucher
    {
        return HotspotVoucher::query()->create([
            'router_id' => $router->id,
            'username' => $username,
            'password' => 'voucher-secret',
            'profile' => 'hs-normal',
        ]);
    }

    private function loginAdmin(): User
    {
        return User::query()->create([
            'name' => 'Hotspot test admin',
            'email' => 'admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);
    }
}
