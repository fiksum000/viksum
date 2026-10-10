<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerRouterSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_offers_sync_action_for_configured_active_pppoe_customer(): void
    {
        [$router, $package, $customer] = $this->pppoeCustomer();

        $this->loginAdmin();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Sinkronkan ke MikroTik')
            ->assertSee(route('customers.sync', $customer));
    }

    public function test_sync_updates_only_a_ppp_secret_with_a_known_customer_profile(): void
    {
        [$router, $package, $customer] = $this->pppoeCustomer();
        $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('findPppSecret')->once()
            ->withArgs(fn ($r, $username) => $r->id === $router->id && $username === $customer->pppoe_username)
            ->andReturn([[
                '.id' => '*10',
                'name' => $customer->pppoe_username,
                'service' => 'pppoe',
                'profile' => 'PPP-NORMAL',
            ]]);
        $routerOs->shouldReceive('createOrUpdatePppSecret')->once()
            ->withArgs(fn ($r, $data, $previous) =>
                $r->id === $router->id
                && $data['name'] === $customer->pppoe_username
                && $data['profile'] === 'PPP-NORMAL'
                && $previous === $customer->pppoe_username
            );
        $routerOs->shouldReceive('enablePppSecret')->once()
            ->withArgs(fn ($r, $username, $enabled) =>
                $r->id === $router->id && $username === $customer->pppoe_username && $enabled === true
            );
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => User::query()->where('email', 'sync-admin@example.test')->value('id')])
            ->post(route('customers.sync', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success', 'Akun pelanggan berhasil disinkronkan ke MikroTik.');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_sync_refuses_to_overwrite_ppp_secret_with_an_unknown_profile(): void
    {
        [$router, $package, $customer] = $this->pppoeCustomer();
        $this->loginAdmin();

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('findPppSecret')->once()
            ->andReturn([[
                '.id' => '*11',
                'name' => $customer->pppoe_username,
                'service' => 'pppoe',
                'profile' => 'UNRELATED-MANUAL-PROFILE',
            ]]);
        $routerOs->shouldNotReceive('createOrUpdatePppSecret');
        $routerOs->shouldNotReceive('enablePppSecret');
        $this->app->instance(RouterOsService::class, $routerOs);

        $admin = User::query()->where('email', 'sync-admin@example.test')->firstOrFail();
        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.sync', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('warning', 'Sinkronisasi belum berhasil. Periksa koneksi router, data layanan, dan log aplikasi.');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    private function pppoeCustomer(): array
    {
        $router = Router::query()->create([
            'name' => 'Resync router',
            'host' => '192.0.2.28',
            'port' => 8728,
            'username' => 'api-test',
            'password' => 'api-test-password',
            'enabled' => true,
        ]);

        $package = Package::query()->create([
            'name' => 'Resync PPP package',
            'router_id' => $router->id,
            'price' => 100000,
            'normal_profile' => 'PPP-NORMAL',
            'sync_status' => 'synced',
            'fup_enabled' => false,
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'RESYNC-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Resync PPP customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'ppp-resync-'.strtolower(bin2hex(random_bytes(3))),
            'pppoe_password' => 'router-secret',
            'pppoe_profile_normal' => 'PPP-NORMAL',
        ]);

        return [$router, $package, $customer];
    }

    private function loginAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Sync admin',
            'email' => 'sync-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);

        $this->withSession(['user_id' => $user->id]);
        return $user;
    }
}
