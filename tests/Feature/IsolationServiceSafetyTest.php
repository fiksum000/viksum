<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FupState;
use App\Models\HotspotProfile;
use App\Models\Package;
use App\Models\Router;
use App\Services\IsolationService;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class IsolationServiceSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pppoe_isolation_updates_router_first_then_billing_status(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta', 'billing.isolation_method' => 'profile', 'billing.isolation_profile' => 'ISOLIR']);
        $router = $this->router();
        $package = $this->package($router);
        $customer = $this->customer($router, $package, [
            'status' => 'active',
            'pppoe_profile_isolir' => 'ISOLIR',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('setPppProfile')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username, string $profile) =>
                $actualRouter->id === $router->id && $username === $customer->pppoe_username && $profile === 'ISOLIR');
        $routerOs->shouldReceive('disconnectPppActive')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username) =>
                $actualRouter->id === $router->id && $username === $customer->pppoe_username);
        $this->app->instance(RouterOsService::class, $routerOs);

        app(IsolationService::class)->isolate($customer);

        $this->assertSame('isolated', $customer->fresh()->status);
    }

    public function test_router_failure_does_not_mark_customer_isolated(): void
    {
        config(['billing.isolation_method' => 'disable']);
        $router = $this->router();
        $package = $this->package($router);
        $customer = $this->customer($router, $package, ['status' => 'active']);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('enablePppSecret')
            ->once()
            ->andThrow(new \RuntimeException('router unavailable'));
        $routerOs->shouldNotReceive('disconnectPppActive');
        $this->app->instance(RouterOsService::class, $routerOs);

        try {
            app(IsolationService::class)->isolate($customer);
            $this->fail('Expected a RouterOS error to abort isolation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('router unavailable', $exception->getMessage());
        }

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_unisolation_restores_the_limited_fup_profile_before_enabling_pppoe(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta', 'billing.isolation_method' => 'disable']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));
        $router = $this->router();
        $package = $this->package($router, true);
        $customer = $this->customer($router, $package, [
            'status' => 'isolated',
            'fup_override' => null,
            'fup_enabled' => false,
        ]);
        FupState::query()->create([
            'customer_id' => $customer->id,
            'period' => app(\App\Services\FupService::class)->currentPeriod(),
            'last_rx' => 0,
            'last_tx' => 0,
            'total_bytes' => 2000,
            'limited' => true,
            'last_sampled_at' => now(),
            'last_session_id' => '*isolated-fup',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('setPppProfile')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username, string $profile) =>
                $actualRouter->id === $router->id
                && $username === $customer->pppoe_username
                && $profile === $package->fup_speed_after);
        $routerOs->shouldReceive('enablePppSecret')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username, bool $enabled) =>
                $actualRouter->id === $router->id && $username === $customer->pppoe_username && $enabled === true);
        $this->app->instance(RouterOsService::class, $routerOs);

        app(IsolationService::class)->unisolate($customer);

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_hotspot_isolation_disables_and_disconnects_the_customer_session(): void
    {
        $router = $this->router();
        $package = $this->package($router);
        $customer = $this->customer($router, $package, [
            'service_type' => 'hotspot',
            'status' => 'active',
            'hotspot_username' => 'hotspot-isolation-user',
            'hotspot_password' => 'test-secret',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('setHotspotUserEnabled')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username, bool $enabled) =>
                $actualRouter->id === $router->id && $username === 'hotspot-isolation-user' && $enabled === false);
        $routerOs->shouldReceive('disconnectHotspotActive')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username) =>
                $actualRouter->id === $router->id && $username === 'hotspot-isolation-user');
        $this->app->instance(RouterOsService::class, $routerOs);

        app(IsolationService::class)->isolate($customer);

        $this->assertSame('isolated', $customer->fresh()->status);
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Isolation test router '.Router::query()->count(),
            'host' => '192.0.2.'.(100 + Router::query()->count()),
            'port' => 8728,
            'username' => 'isolation-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
    }

    private function package(Router $router, bool $fupEnabled = false): Package
    {
        return Package::query()->create([
            'name' => 'Isolation test package '.$router->id,
            'price' => 50000,
            'router_id' => $router->id,
            'normal_profile' => 'VIKSUM-PPP-'.$router->id,
            'fup_speed_after' => $fupEnabled ? 'VIKSUM-PPP-'.$router->id.'-FUP' : null,
            'fup_enabled' => $fupEnabled,
            'fup_limit_bytes' => $fupEnabled ? 1000 : null,
        ]);
    }

    private function customer(Router $router, Package $package, array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'customer_code' => 'ISOLATION-'.str_pad((string) (Customer::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Isolation test customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'isolation-user-'.$router->id,
            'pppoe_profile_normal' => $package->normal_profile,
            'fup_speed_after' => $package->fup_speed_after,
            'is_auto_isolate' => true,
        ], $overrides));
    }
}
