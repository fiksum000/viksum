<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\User;
use App\Services\RouterOsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomerConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_separates_billing_and_live_router_statuses(): void
    {
        $router = Router::query()->create([
            'name' => 'Test router',
            'host' => '192.0.2.1',
            'port' => 8728,
            'username' => 'readonly-test',
            'password' => 'test-password',
        ]);
        $online = $this->customer($router, 'ppp-online', 'Online billing', 'active');
        $offline = $this->customer($router, 'ppp-offline', 'Offline billing', 'active');
        $isolated = $this->customer($router, 'ppp-isolated', 'Isolated billing', 'isolated');

        $this->invoice($online, 'paid');
        $this->invoice($offline, 'unpaid');
        $this->invoice($isolated, 'unpaid');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppMap')->once()->andReturn([
            'ppp-online' => ['name' => 'ppp-online', 'service' => 'pppoe'],
        ]);
        $routerOs->shouldReceive('listHotspotActive')->once()->andReturn([]);
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Online billing')
            ->assertSee('Offline billing')
            ->assertSee('Isolated billing')
            ->assertSee('Aktif di billing')
            ->assertSee('Online')
            ->assertSee('Offline')
            ->assertSee('Lunas')
            ->assertSee('Masa tagihan')
            ->assertSee('Isolir');
    }

    public function test_router_read_failure_is_unknown_instead_of_offline(): void
    {
        $router = Router::query()->create([
            'name' => 'Unavailable router',
            'host' => '192.0.2.2',
            'port' => 8728,
            'username' => 'readonly-test',
            'password' => 'test-password',
        ]);
        $this->customer($router, 'ppp-unknown', 'Unknown billing', 'active');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('activePppMap')->once()->andThrow(new \RuntimeException('Router unavailable'));
        $routerOs->shouldReceive('listHotspotActive')->never();
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Tidak diketahui')
            ->assertSee('Router tidak dapat dibaca');
    }

    private function customer(Router $router, string $username, string $name, string $status): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'CUST-'.strtoupper(str_replace('-', '', $username)),
            'name' => $name,
            'service_type' => 'pppoe',
            'status' => $status,
            'router_id' => $router->id,
            'pppoe_username' => $username,
        ]);
    }

    private function invoice(Customer $customer, string $status): Invoice
    {
        return Invoice::query()->create([
            'invoice_number' => 'INV-'.strtoupper($customer->pppoe_username),
            'public_token' => bin2hex(random_bytes(24)),
            'customer_id' => $customer->id,
            'period' => now(config('billing.timezone'))->format('Y-m'),
            'issued_at' => now(config('billing.timezone'))->toDateString(),
            'due_date' => now(config('billing.timezone'))->addDays(7)->toDateString(),
            'subtotal' => 100000,
            'total' => 100000,
            'status' => $status,
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
