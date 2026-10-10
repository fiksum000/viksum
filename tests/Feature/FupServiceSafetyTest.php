<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FupState;
use App\Models\Package;
use App\Models\Router;
use App\Services\FupService;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class FupServiceSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fup_collection_preserves_limited_state_while_customer_is_isolated(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));
        [$customer, $state] = $this->isolatedCustomerWithFupState('2026-10');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('setPppProfile');
        $routerOs->shouldNotReceive('disconnectPppActive');
        $routerOs->shouldNotReceive('activePppMap');
        $this->app->instance(RouterOsService::class, $routerOs);

        app(FupService::class)->collect();

        $this->assertTrue($state->fresh()->limited);
        $this->assertSame(9000, $state->fresh()->total_bytes);
        $this->assertSame('isolated', $customer->fresh()->status);
    }

    public function test_monthly_fup_reset_does_not_touch_the_router_for_an_isolated_customer(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));
        [$customer, $state] = $this->isolatedCustomerWithFupState('2026-09');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('setPppProfile');
        $routerOs->shouldNotReceive('disconnectPppActive');
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->assertSame(1, app(FupService::class)->resetMonthly());

        $this->assertFalse($state->fresh()->limited);
        $this->assertSame(0, $state->fresh()->total_bytes);
        $this->assertSame('isolated', $customer->fresh()->status);
        $this->assertDatabaseHas('fup_logs', [
            'customer_id' => $customer->id,
            'period' => '2026-09',
            'action' => 'reset',
            'profile_after' => null,
        ]);
    }

    private function isolatedCustomerWithFupState(string $period): array
    {
        $router = Router::query()->create([
            'name' => 'FUP safety router',
            'host' => '192.0.2.60',
            'port' => 8728,
            'username' => 'fup-safety',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        $package = Package::query()->create([
            'name' => 'FUP safety package',
            'price' => 50000,
            'router_id' => $router->id,
            'normal_profile' => 'VIKSUM-PPP-77',
            'fup_speed_after' => 'VIKSUM-PPP-77-FUP',
            'fup_enabled' => true,
            'fup_limit_bytes' => 1000,
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'FUP-SAFETY-'.str_replace('-', '', $period),
            'name' => 'Isolated FUP customer',
            'service_type' => 'pppoe',
            'status' => 'isolated',
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'fup-safety-user',
            'pppoe_profile_normal' => $package->normal_profile,
            'fup_speed_after' => $package->fup_speed_after,
        ]);
        $state = FupState::query()->create([
            'customer_id' => $customer->id,
            'period' => $period,
            'last_rx' => 4000,
            'last_tx' => 5000,
            'total_bytes' => 9000,
            'limited' => true,
            'last_sampled_at' => now(),
            'last_session_id' => '*fup-safety',
        ]);

        return [$customer, $state];
    }
}
