<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CustomerTrafficTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_traffic_endpoint_uses_current_read_only_pppoe_rates(): void
    {
        $router = $this->router();
        $customer = $this->customer($router, 'pppoe-one');
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppTrafficMap')->once()->andReturn(
            ['pppoe-one' => ['session_id' => 'session-1', 'caller_id' => 'AA:BB', 'address' => '192.0.2.20', 'download_bps' => 1_250_000, 'upload_bps' => 500_000]],
        );
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'online')
            ->assertJsonPath("customers.{$customer->id}.download_bps", 1_250_000)
            ->assertJsonPath("customers.{$customer->id}.upload_bps", 500_000)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_offline_pppoe_customer_has_no_zero_rate(): void
    {
        $router = $this->router();
        $customer = $this->customer($router, 'pppoe-offline');
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppTrafficMap')->once()->andReturn([]);
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))
            ->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'offline')
            ->assertJsonMissingPath("customers.{$customer->id}.download_bps");
    }

    public function test_active_pppoe_without_readable_interface_rate_is_not_reported_offline(): void
    {
        $router = $this->router();
        $customer = $this->customer($router, 'pppoe-rate-unavailable');
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppTrafficMap')->once()->andReturn(
            ['pppoe-rate-unavailable' => ['session_id' => 'session-1', 'caller_id' => 'AA:BB', 'address' => '192.0.2.21', 'download_bps' => null, 'upload_bps' => null]],
        );
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'unavailable');
    }

    public function test_hotspot_customer_traffic_uses_router_received_and_transmitted_counters(): void
    {
        Cache::flush();
        $router = $this->router();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-HOTSPOT-1',
            'name' => 'Hotspot traffic test',
            'service_type' => 'hotspot',
            'status' => 'active',
            'router_id' => $router->id,
            'hotspot_username' => 'hotspot-one',
        ]);
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activeHotspotTrafficMap')->twice()->andReturn(
            ['hotspot-one' => ['session_id' => '*1', 'caller_id' => 'AA:CC', 'address' => '192.0.2.30', 'download_bytes' => 1000, 'upload_bytes' => 500]],
            ['hotspot-one' => ['session_id' => '*1', 'caller_id' => 'AA:CC', 'address' => '192.0.2.30', 'download_bytes' => 3_751_000, 'upload_bytes' => 1_875_500]],
        );
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'sampling');
        $this->travel(30)->seconds();
        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.download_bps", 1_000_000)
            ->assertJsonPath("customers.{$customer->id}.upload_bps", 500_000);
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Read only test router',
            'host' => '192.0.2.1',
            'port' => 8728,
            'username' => 'readonly-test',
            'password' => 'test-password',
        ]);
    }

    private function customer(Router $router, string $username): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'CUST-'.strtoupper(str_replace('-', '', $username)),
            'name' => 'Traffic test',
            'service_type' => 'pppoe',
            'status' => 'active',
            'router_id' => $router->id,
            'pppoe_username' => $username,
        ]);
    }

    private function loginAdmin(): void
    {
        $user = User::query()->create([
            'name' => 'Test administrator',
            'email' => 'admin@example.test',
            'password' => 'test-password',
            'role' => 'super_admin',
        ]);
        $this->withSession(['user_id' => $user->id]);
    }
}

