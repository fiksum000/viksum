<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\IsolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerManualIsolationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_shows_manual_isolation_actions_for_active_customer(): void
    {
        $admin = $this->loginAdmin();
        $customer = $this->customer('active');

        $this->withSession(['user_id' => $admin->id])
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Isolir')
            ->assertSee(route('customers.isolate', $customer))
            ->assertDontSee('Buka isolir');
    }

    public function test_manual_isolation_route_calls_service_and_returns_feedback(): void
    {
        $admin = $this->loginAdmin();
        $customer = $this->customer('active');

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldReceive('isolate')->once()
            ->withArgs(fn (Customer $argument) => $argument->is($customer));
        $this->app->instance(IsolationService::class, $isolation);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.isolate', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success');
    }

    public function test_manual_isolation_does_not_expose_raw_router_exception_to_browser(): void
    {
        $admin = $this->loginAdmin();
        $customer = $this->customer('active');

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldReceive('isolate')->once()
            ->andThrow(new \\RuntimeException('sensitive-router-host-and-api-details'));
        $this->app->instance(IsolationService::class, $isolation);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.isolate', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('warning', 'Pelanggan belum diisolir. Periksa koneksi router dan log aplikasi.')
            ->assertSessionMissing('error');
    }

    public function test_manual_unisolation_route_calls_service_and_returns_feedback(): void
    {
        $admin = $this->loginAdmin();
        $customer = $this->customer('isolated');

        $isolation = Mockery::mock(IsolationService::class);
        $isolation->shouldReceive('unisolate')->once()
            ->withArgs(fn (Customer $argument) => $argument->is($customer));
        $this->app->instance(IsolationService::class, $isolation);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.unisolate', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success');
    }

    private function customer(string $status): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'MANUAL-ISOLATION-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Manual isolation test customer',
            'service_type' => 'pppoe',
            'status' => $status,
            'due_day' => 20,
        ]);
    }

    private function loginAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Isolation admin',
            'email' => 'isolation-admin-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);

        $this->withSession(['user_id' => $user->id]);
        return $user;
    }
}
