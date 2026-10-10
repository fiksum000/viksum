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
use RuntimeException;
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
        $routerOs->shouldNotReceive('setPppProfileIfCurrentProfile');
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
        $routerOs->shouldNotReceive('setPppProfileIfCurrentProfile');
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

    public function test_collector_applies_the_fup_profile_only_once_when_the_limit_is_first_reached(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));

        $router = Router::query()->create([
            'name' => 'Active FUP router',
            'host' => '192.0.2.61',
            'port' => 8728,
            'username' => 'active-fup-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        $package = Package::query()->create([
            'name' => 'Active FUP package',
            'price' => 50000,
            'router_id' => $router->id,
            'normal_profile' => 'VIKSUM-PPP-78',
            'fup_speed_after' => 'VIKSUM-PPP-78-FUP',
            'fup_enabled' => true,
            'fup_limit_bytes' => 1000,
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'FUP-ACTIVE-001',
            'name' => 'Active FUP customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'fup-active-user',
            'pppoe_profile_normal' => $package->normal_profile,
            'fup_enabled' => true,
            'fup_override' => true,
            'fup_limit_bytes' => 1000,
            'fup_speed_after' => $package->fup_speed_after,
        ]);
        $state = FupState::query()->create([
            'customer_id' => $customer->id,
            'period' => app(FupService::class)->currentPeriod(),
            'last_rx' => 0,
            'last_tx' => 0,
            'total_bytes' => 0,
            'limited' => false,
            'last_sampled_at' => now()->subMinutes(5),
            'last_session_id' => '*active-fup',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppMap')
            ->once()
            ->withArgs(fn (Router $actualRouter) => $actualRouter->id === $router->id)
            ->andReturn([
                'fup-active-user' => [
                    '.id' => '*active-fup',
                    'name' => 'fup-active-user',
                    'service' => 'pppoe',
                    'bytes-in' => 1200,
                    'bytes-out' => 800,
                ],
            ]);
        $routerOs->shouldReceive('listPppSecrets')
            ->once()
            ->andReturn([[
                '.id' => '*secret-fup-active',
                'name' => 'fup-active-user',
                'profile' => $package->normal_profile,
            ]]);
        $profileChanges = [];
        $routerOs->shouldReceive('setPppProfileIfCurrentProfile')
            ->once()
            ->withArgs(fn (Router $actualRouter, string $username, array $expected, string $profile) =>
                $actualRouter->id === $router->id
                && $username === 'fup-active-user'
                && in_array($package->normal_profile, $expected, true)
                && $profile === $package->fup_speed_after)
            ->andReturnUsing(function (Router $actualRouter, string $username, array $expected, string $profile) use (&$profileChanges): bool {
                $profileChanges[] = [$actualRouter->id, $username, $profile];
                return true;
            });
        $routerOs->shouldNotReceive('setPppProfile');
        $routerOs->shouldNotReceive('disconnectPppActive');
        $this->app->instance(RouterOsService::class, $routerOs);

        app(FupService::class)->collect();

        $this->assertSame(2000, $state->fresh()->total_bytes);
        $this->assertTrue($state->fresh()->limited);
        $this->assertSame([[$router->id, 'fup-active-user', $package->fup_speed_after]], $profileChanges);
    }

    public function test_collector_ignores_a_non_pppoe_session_that_has_the_same_username(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));

        $router = Router::query()->create([
            'name' => 'Non-PPPoE session router',
            'host' => '192.0.2.62',
            'port' => 8728,
            'username' => 'session-type-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        $package = Package::query()->create([
            'name' => 'Session type FUP package',
            'price' => 50000,
            'router_id' => $router->id,
            'normal_profile' => 'VIKSUM-PPP-79',
            'fup_speed_after' => 'VIKSUM-PPP-79-FUP',
            'fup_enabled' => true,
            'fup_limit_bytes' => 1000,
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'FUP-SESSION-TYPE',
            'name' => 'Session type customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'shared-session-name',
            'pppoe_profile_normal' => $package->normal_profile,
            'fup_enabled' => true,
            'fup_override' => true,
            'fup_limit_bytes' => 1000,
            'fup_speed_after' => $package->fup_speed_after,
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppMap')->once()->andReturn([
            'shared-session-name' => [
                '.id' => '*non-pppoe-session',
                'name' => 'shared-session-name',
                'service' => 'pptp',
                'bytes-in' => 5000,
                'bytes-out' => 5000,
            ],
        ]);
        $routerOs->shouldReceive('listPppSecrets')->once()->andReturn([[
            '.id' => '*secret-session-type',
            'name' => 'shared-session-name',
            'profile' => $package->normal_profile,
        ]]);
        $routerOs->shouldNotReceive('setPppProfileIfCurrentProfile');
        $routerOs->shouldNotReceive('setPppProfile');
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->assertSame(0, app(FupService::class)->collect());
        $this->assertDatabaseMissing('fup_states', ['customer_id' => $customer->id]);
    }

    public function test_collector_keeps_fup_state_unlimited_when_router_rejects_an_unknown_profile_transition(): void
    {
        config(['billing.timezone' => 'Asia/Jakarta']);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Jakarta'));

        $router = Router::query()->create([
            'name' => 'Unknown profile router',
            'host' => '192.0.2.63',
            'port' => 8728,
            'username' => 'unknown-profile-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        $package = Package::query()->create([
            'name' => 'Unknown profile FUP package',
            'price' => 50000,
            'router_id' => $router->id,
            'normal_profile' => 'VIKSUM-PPP-80',
            'fup_speed_after' => 'VIKSUM-PPP-80-FUP',
            'fup_enabled' => true,
            'fup_limit_bytes' => 1000,
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'FUP-UNKNOWN-PROFILE',
            'name' => 'Unknown profile customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_username' => 'unknown-profile-user',
            'pppoe_profile_normal' => $package->normal_profile,
            'fup_enabled' => true,
            'fup_override' => true,
            'fup_limit_bytes' => 1000,
            'fup_speed_after' => $package->fup_speed_after,
        ]);
        $state = FupState::query()->create([
            'customer_id' => $customer->id,
            'period' => app(FupService::class)->currentPeriod(),
            'last_rx' => 0,
            'last_tx' => 0,
            'total_bytes' => 0,
            'limited' => false,
            'last_sampled_at' => now()->subMinutes(5),
            'last_session_id' => '*unknown-profile-session',
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppMap')->once()->andReturn([
            'unknown-profile-user' => [
                '.id' => '*unknown-profile-session',
                'name' => 'unknown-profile-user',
                'service' => 'pppoe',
                'bytes-in' => 1200,
                'bytes-out' => 800,
            ],
        ]);
        $routerOs->shouldReceive('listPppSecrets')->once()->andReturn([[
            '.id' => '*secret-unknown-profile',
            'name' => 'unknown-profile-user',
            'profile' => 'UNRELATED-MANUAL-PROFILE',
        ]]);
        $routerOs->shouldReceive('setPppProfileIfCurrentProfile')->once()
            ->andThrow(new RuntimeException('Unexpected manual PPP profile'));
        $routerOs->shouldNotReceive('setPppProfile');
        $routerOs->shouldNotReceive('disconnectPppActive');
        $this->app->instance(RouterOsService::class, $routerOs);

        app(FupService::class)->collect();

        $this->assertSame(2000, $state->fresh()->total_bytes);
        $this->assertFalse($state->fresh()->limited);
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
