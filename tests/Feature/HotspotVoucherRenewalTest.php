<?php

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use App\Models\Router;
use App\Models\User;
use App\Services\HotspotVoucherLifecycleService;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class HotspotVoucherRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_renew_expired_managed_voucher_without_resetting_usage(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00', config('billing.timezone')));
        $admin = $this->loginAdmin();
        [$router, $profile, $voucher] = $this->managedVoucher('expired', Carbon::parse('2026-10-10 10:00:00', config('billing.timezone')));

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')->once()
            ->withArgs(fn (HotspotProfile $actual) => $actual->id === $profile->id && $actual->router_id === $router->id);
        $routerOs->shouldReceive('disconnectHotspotActive')->once()
            ->withArgs(fn ($actualRouter, $username) => $actualRouter->id === $router->id && $username === $voucher->username);
        $routerOs->shouldReceive('createOrUpdateHotspotUser')->once()
            ->withArgs(fn ($actualRouter, $username, $password, $routerProfile, $comment, $previousUsername, $enabled, $expectedComment) =>
                $actualRouter->id === $router->id
                && $username === $voucher->username
                && $password === 'renewal-password'
                && $routerProfile === $profile->routerProfileName()
                && $comment === 'VIKSUM:V:'.$voucher->id
                && $previousUsername === $voucher->username
                && $enabled === true
                && $expectedComment === 'VIKSUM:V:'.$voucher->id
            );
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('hotspot.index'))
            ->post(route('hotspot.vouchers.renew', $voucher))
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('success');

        $voucher->refresh();
        $this->assertSame('active', $voucher->status);
        $this->assertSame('synced', $voucher->sync_status);
        $this->assertSame(5_000_000, $voucher->total_bytes_used);
        $this->assertFalse($voucher->fup_applied);
        $this->assertSame(
            Carbon::parse('2026-10-12 10:00:00', config('billing.timezone'))->toDateTimeString(),
            $voucher->expires_at->timezone(config('billing.timezone'))->toDateTimeString(),
        );
    }

    public function test_active_voucher_renewal_extends_from_current_future_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00', config('billing.timezone')));
        $admin = $this->loginAdmin();
        [$router, $profile, $voucher] = $this->managedVoucher('active', Carbon::parse('2026-10-13 10:00:00', config('billing.timezone')));

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('syncHotspotProfile')->once();
        $routerOs->shouldReceive('disconnectHotspotActive')->never();
        $routerOs->shouldReceive('createOrUpdateHotspotUser')->once()
            ->withArgs(fn ($actualRouter, $username, $password, $routerProfile, $comment, $previousUsername, $enabled, $expectedComment) =>
                $actualRouter->id === $router->id
                && $username === $voucher->username
                && $routerProfile === $profile->routerProfileName()
                && $enabled === true
                && $expectedComment === 'VIKSUM:V:'.$voucher->id
            );
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('hotspot.index'))
            ->post(route('hotspot.vouchers.renew', $voucher))
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            Carbon::parse('2026-10-14 10:00:00', config('billing.timezone'))->toDateTimeString(),
            $voucher->fresh()->expires_at->timezone(config('billing.timezone'))->toDateTimeString(),
        );
    }

    public function test_first_login_voucher_without_a_login_timestamp_cannot_be_renewed(): void
    {
        $admin = $this->loginAdmin();
        [$router, $profile, $voucher] = $this->managedVoucher('active', null, true);
        $voucher->update(['first_login_at' => null]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('syncHotspotProfile');
        $routerOs->shouldNotReceive('createOrUpdateHotspotUser');
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('hotspot.index'))
            ->post(route('hotspot.vouchers.renew', $voucher))
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('hotspot_vouchers', ['id' => $voucher->id, 'status' => 'active']);
    }

    public function test_legacy_voucher_without_managed_profile_cannot_be_renewed_automatically(): void
    {
        $admin = $this->loginAdmin();
        $router = $this->router();
        $voucher = HotspotVoucher::query()->create([
            'router_id' => $router->id,
            'username' => 'legacy-renew',
            'password' => 'legacy-password',
            'profile' => 'default',
            'status' => 'expired',
            'sync_status' => 'synced',
            'expires_at' => Carbon::now(config('billing.timezone'))->subDay(),
        ]);

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('syncHotspotProfile');
        $routerOs->shouldNotReceive('createOrUpdateHotspotUser');
        $this->app->instance(RouterOsService::class, $routerOs);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('hotspot.index'))
            ->post(route('hotspot.vouchers.renew', $voucher))
            ->assertRedirect(route('hotspot.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('hotspot_vouchers', ['id' => $voucher->id, 'status' => 'expired']);
    }

    private function managedVoucher(string $status, ?Carbon $expiry, bool $startsOnFirstLogin = false): array
    {
        $router = $this->router();
        $profile = HotspotProfile::query()->create([
            'router_id' => $router->id,
            'name' => 'Voucher renewal profile',
            'download_speed' => '10M',
            'upload_speed' => '2M',
            'shared_users' => 1,
            'validity_value' => 1,
            'validity_unit' => 'day',
            'starts_on_first_login' => $startsOnFirstLogin,
            'enabled' => true,
            'sync_status' => 'synced',
        ]);

        $voucher = HotspotVoucher::query()->create([
            'router_id' => $router->id,
            'hotspot_profile_id' => $profile->id,
            'username' => 'renew-'.strtolower($status).'-'.bin2hex(random_bytes(3)),
            'password' => 'renewal-password',
            'profile' => $profile->name,
            'comment' => 'VIKSUM:V:PENDING',
            'status' => $status,
            'sync_status' => 'synced',
            'expires_at' => $expiry,
            'first_login_at' => Carbon::now(config('billing.timezone'))->subDays(2),
            'total_bytes_used' => 5_000_000,
            'last_bytes_in' => 3_000_000,
            'last_bytes_out' => 2_000_000,
            'fup_applied' => false,
        ]);
        $voucher->update(['comment' => 'VIKSUM:V:'.$voucher->id]);

        return [$router, $profile, $voucher->fresh()];
    }

    private function router(): Router
    {
        return Router::query()->create([
            'name' => 'Voucher renewal router',
            'host' => '192.0.2.49',
            'port' => 8728,
            'username' => 'renewal-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
    }

    private function loginAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Voucher renewal admin',
            'email' => 'renewal-admin-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);
        $this->withSession(['user_id' => $admin->id]);

        return $admin;
    }
}
