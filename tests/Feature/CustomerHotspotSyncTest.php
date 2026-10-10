<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HotspotProfile;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerHotspotSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_hotspot_customer_synchronizes_billing_profile_and_credentials_to_routeros(): void
    {
        $router = $this->router();
        $package = $this->package();
        $profile = $this->profile($router, 'hs-normal');
        $admin = $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')
            ->once()
            ->withArgs(fn ($actualProfile) => $actualProfile->id === $profile->id);
        $routerOs->shouldReceive('createOrUpdateHotspotUser')
            ->once()
            ->withArgs(fn ($actualRouter, $username, $password, $routerProfile, $comment, $previousUsername, $enabled) =>
                $actualRouter->id === $router->id
                && $username === 'hs-customer-1'
                && $password === 'hotspot-secret'
                && $routerProfile === $profile->routerProfileName()
                && str_contains($comment, 'Billing customer')
                && $previousUsername === null
                && $enabled === true);
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.store'), $this->payload($router, $package, [
                'hotspot_username' => 'hs-customer-1',
                'hotspot_password' => 'hotspot-secret',
                'hotspot_profile_id' => $profile->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $customer = Customer::query()->where('hotspot_username', 'hs-customer-1')->firstOrFail();
        $this->assertSame($profile->name, $customer->hotspot_profile);
        $this->assertSame($profile->id, $customer->hotspot_profile_id);
        $this->assertSame('hotspot-secret', $customer->hotspot_password);
        $this->assertSame($router->id, $customer->router_id);
    }

    public function test_editing_hotspot_customer_uses_managed_profile_and_keeps_password_when_blank(): void
    {
        $router = $this->router();
        $package = $this->package();
        $oldProfile = $this->profile($router, 'hs-normal');
        $newProfile = $this->profile($router, 'hs-premium');
        $admin = $this->loginAdmin();

        $customer = Customer::query()->create([
            'customer_code' => '728828009',
            'name' => 'Existing Hotspot Customer',
            'area' => 'Area A',
            'service_type' => 'hotspot',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'hotspot_username' => 'hs-old-name',
            'hotspot_password' => 'current-secret',
            'hotspot_profile' => $oldProfile->name,
            'hotspot_profile_id' => $oldProfile->id,
            'portal_password' => 'WIFIpass',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')
            ->once()
            ->withArgs(fn ($actualProfile) => $actualProfile->id === $newProfile->id);
        $routerOs->shouldReceive('createOrUpdateHotspotUser')
            ->once()
            ->withArgs(fn ($actualRouter, $username, $password, $routerProfile, $comment, $previousUsername, $enabled) =>
                $actualRouter->id === $router->id
                && $username === 'hs-new-name'
                && $password === 'current-secret'
                && $routerProfile === $newProfile->routerProfileName()
                && $previousUsername === 'hs-old-name'
                && $enabled === true);
        $this->app->instance(RouterOsService::class, $routerOs);

        $payload = $this->payload($router, $package, [
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'hotspot_username' => 'hs-new-name',
            'hotspot_password' => '',
            'hotspot_profile_id' => $newProfile->id,
            'portal_password' => 'WIFIpass',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->put(route('customers.update', $customer), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('hs-new-name', $customer->hotspot_username);
        $this->assertSame('current-secret', $customer->hotspot_password);
        $this->assertSame($newProfile->name, $customer->hotspot_profile);
        $this->assertSame($newProfile->id, $customer->hotspot_profile_id);
    }

    private function payload(Router $router, Package $package, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Hotspot Customer',
            'area' => 'Area A',
            'service_type' => 'hotspot',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'fup_mode' => 'inherit',
            'portal_password' => 'WIFIpass',
        ], $overrides);
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Customer Hotspot test router',
            'host' => '192.0.2.41',
            'port' => 8728,
            'username' => 'billing-customer-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
    }

    private function profile(Router $router, string $name): HotspotProfile
    {
        return HotspotProfile::query()->create([
            'router_id' => $router->id,
            'name' => $name,
            'download_speed' => '10M',
            'upload_speed' => '2M',
            'shared_users' => 1,
            'starts_on_first_login' => true,
            'enabled' => true,
            'sync_status' => 'synced',
        ]);
    }

    private function package(): Package
    {
        return Package::query()->create([
            'name' => 'Hotspot Basic',
            'price' => 80000,
            'normal_profile' => 'default',
        ]);
    }

    private function loginAdmin(): User
    {
        return User::query()->create([
            'name' => 'Customer Hotspot admin',
            'email' => 'customer-hotspot-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);
    }
}
