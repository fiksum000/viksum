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

    public function test_customer_traffic_endpoint_uses_read_only_samples_to_calculate_rates(): void
    {
        Cache::flush();
        $router = $this->router();
        $customer = $this->customer($router, 'pppoe-one');
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppTrafficMap')->twice()->andReturn(
            ['pppoe-one' => ['session_id' => 'session-1', 'caller_id' => 'AA:BB', 'address' => '192.0.2.20', 'download_bytes' => 1000, 'upload_bytes' => 500]],
            ['pppoe-one' => ['session_id' => 'session-1', 'caller_id' => 'AA:BB', 'address' => '192.0.2.20', 'download_bytes' => 3_751_000, 'upload_bytes' => 1_875_500]],
        );
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()->assertJsonPath("customers.{$customer->id}.state", 'sampling');
        $this->travel(30)->seconds();
        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'online')
            ->assertJsonPath("customers.{$customer->id}.download_bps", 1_000_000)
            ->assertJsonPath("customers.{$customer->id}.upload_bps", 500_000)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_offline_customer_does_not_show_a_zero_rate(): void
    {
        $router = $this->router();
        $customer = $this->customer($router, 'pppoe-offline');
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppTrafficMap')->once()->andReturn([]);
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.state", 'offline')
            ->assertJsonMissingPath("customers.{$customer->id}.download_bps");
    }

    public function test_hotspot_traffic_is_sampled_separately(): void
    {
        Cache::flush();
        $router = $this->router();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-HOTSPOT-1', 'name' => 'Hotspot traffic test', 'service_type' => 'hotspot',
            'status' => 'active', 'router_id' => $router->id, 'hotspot_username' => 'hotspot-one',
        ]);
        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activeHotspotTrafficMap')->twice()->andReturn(
            ['hotspot-one' => ['session_id' => '*1', 'caller_id' => 'AA:CC', 'address' => '192.0.2.30', 'download_bytes' => 1000, 'upload_bytes' => 500]],
            ['hotspot-one' => ['session_id' => '*1', 'caller_id' => 'AA:CC', 'address' => '192.0.2.30', 'download_bytes' => 3_751_000, 'upload_bytes' => 1_875_500]],
        );
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.traffic'))->assertOk()->assertJsonPath("customers.{$customer->id}.state", 'sampling');
        $this->travel(30)->seconds();
        $this->get(route('customers.traffic'))->assertOk()
            ->assertJsonPath("customers.{$customer->id}.download_bps", 1_000_000)
            ->assertJsonPath("customers.{$customer->id}.upload_bps", 500_000);
    }

    private function router(): Router
    {
        return Router::query()->create(['name' => 'Read only test router', 'host' => '192.0.2.1', 'port' => 8728, 'username' => 'readonly-test', 'password' => 'test-password']);
    }

    private function customer(Router $router, string $username): Customer
    {
        return Customer::query()->create(['customer_code' => 'CUST-'.strtoupper(str_replace('-', '', $username)), 'name' => 'Traffic test', 'service_type' => 'pppoe', 'status' => 'active', 'router_id' => $router->id, 'pppoe_username' => $username]);
    }

    private function loginAdmin(): void
    {
        $user = User::query()->create(['name' => 'Test administrator', 'email' => 'admin@example.test', 'password' => 'test-password', 'role' => 'super_admin']);
        $this->withSession(['user_id' => $user->id]);
    }
}
