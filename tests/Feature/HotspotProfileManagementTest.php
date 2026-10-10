<?php

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HotspotProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_billing_owned_profile_and_sync_it_to_routeros(): void
    {
        $router = $this->router();
        $admin = $this->loginAdmin();
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')
            ->once()
            ->withArgs(fn ($profile) =>
                $profile->router_id === $router->id
                && $profile->name === 'HEMAT-3JAM'
                && $profile->download_speed === '10M'
                && $profile->upload_speed === '2M'
                && $profile->validity_value === 3
                && $profile->validity_unit === 'hour'
                && $profile->starts_on_first_login === true);
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('hotspot.profiles.store'), [
                'router_id' => $router->id,
                'name' => 'HEMAT-3JAM',
                'download_speed' => '10M',
                'upload_speed' => '2M',
                'shared_users' => 1,
                'fup_limit_gb' => 5,
                'fup_download_speed' => '2M',
                'fup_upload_speed' => '512k',
                'validity_value' => 3,
                'validity_unit' => 'hour',
                'starts_on_first_login' => '1',
                'bind_mac' => '1',
                'enabled' => '1',
            ])
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('success');

        $profile = HotspotProfile::query()->where('name', 'HEMAT-3JAM')->firstOrFail();
        $this->assertSame($router->id, $profile->router_id);
        $this->assertSame('synced', $profile->sync_status);
        $this->assertSame(5 * 1073741824, $profile->fup_limit_bytes);
        $this->assertSame('VIKSUM-HS-'.$profile->id, $profile->routerProfileName());
    }

    public function test_profile_stays_in_billing_and_shows_sync_failure_if_routeros_is_unavailable(): void
    {
        $router = $this->router();
        $admin = $this->loginAdmin();
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')
            ->once()
            ->andThrow(new \RuntimeException('router unavailable'));
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('hotspot.profiles.store'), [
                'router_id' => $router->id,
                'name' => 'PAKET-PENDING',
                'download_speed' => '5M',
                'upload_speed' => '1M',
                'shared_users' => 1,
                'enabled' => '1',
            ])
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('hotspot_profiles', [
            'router_id' => $router->id,
            'name' => 'PAKET-PENDING',
            'sync_status' => 'failed',
        ]);
    }

    public function test_profile_cannot_be_deleted_while_a_voucher_or_customer_uses_it(): void
    {
        $router = $this->router();
        $admin = $this->loginAdmin();
        $profile = HotspotProfile::query()->create([
            'router_id' => $router->id,
            'name' => 'PAKET-TERPAKAI',
            'download_speed' => '5M',
            'upload_speed' => '1M',
            'shared_users' => 1,
            'enabled' => true,
            'sync_status' => 'synced',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('deleteHotspotProfile');
        $this->app->instance(RouterOsService::class, $routerOs);

        // Adding a voucher reference is enough to protect the managed profile.
        \App\Models\HotspotVoucher::query()->create([
            'router_id' => $router->id,
            'hotspot_profile_id' => $profile->id,
            'username' => 'wifi-used',
            'password' => 'secret',
            'profile' => $profile->name,
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->delete(route('hotspot.profiles.destroy', $profile))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('hotspot_profiles', ['id' => $profile->id]);
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Profile test router',
            'host' => '192.0.2.42',
            'port' => 8728,
            'username' => 'profile-test-user',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
    }

    private function loginAdmin(): User
    {
        return User::query()->create([
            'name' => 'Profile test admin',
            'email' => 'profile-test-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);
    }
}
