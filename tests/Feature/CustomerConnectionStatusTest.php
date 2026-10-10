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

    public function test_customer_with_invoice_history_cannot_be_permanently_deleted(): void
    {
        $router = Router::query()->create([
            'name' => 'Billing history router',
            'host' => '192.0.2.20',
            'port' => 8728,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);
        $customer = $this->customer($router, 'ppp-history', 'Customer with history', 'terminated');
        $this->invoice($customer, 'paid');
        $this->loginAdmin();

        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('invoices', ['customer_id' => $customer->id, 'status' => 'paid']);
    }

    public function test_live_customer_cannot_be_permanently_deleted_before_termination(): void
    {
        $router = Router::query()->create([
            'name' => 'Protected active customer router',
            'host' => '192.0.2.24',
            'port' => 8728,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);
        $customer = $this->customer($router, 'ppp-active-protected', 'Active customer must be terminated first', 'active');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldNotReceive('findPppSecret');
        $routerOs->shouldNotReceive('deletePppSecret');
        $routerOs->shouldNotReceive('deleteManagedHotspotUser');
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('warning', 'Hentikan layanan pelanggan terlebih dahulu sebelum menghapus permanen. Pelanggan aktif atau terisolir tidak dihapus langsung.');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'active']);
    }

    public function test_customer_without_invoice_history_can_be_deleted_after_router_account_cleanup(): void
    {
        $router = Router::query()->create([
            'name' => 'Deletion router',
            'host' => '192.0.2.23',
            'port' => 8728,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);
        $customer = $this->customer($router, 'ppp-delete', 'Customer to delete', 'terminated');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('findPppSecret')->once()
            ->andReturn([['.id' => '*2', 'name' => 'ppp-delete', 'service' => 'pppoe', 'profile' => 'ISOLIR']]);
        $routerOs->shouldReceive('disconnectPppActive')->once()
            ->withArgs(fn ($r, $username) => $r->id === $router->id && $username === 'ppp-delete');
        $routerOs->shouldReceive('deletePppSecret')->once()
            ->withArgs(fn ($r, $username) => $r->id === $router->id && $username === 'ppp-delete');
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_customer_detail_page_shows_customer_and_invoice_history(): void
    {
        $router = Router::query()->create([
            'name' => 'Customer detail router',
            'host' => '192.0.2.21',
            'port' => 8728,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);
        $customer = $this->customer($router, 'ppp-detail', 'Customer detail example', 'active');
        $invoice = $this->invoice($customer, 'unpaid');
        $this->loginAdmin();

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Detail Pelanggan')
            ->assertSee('Customer detail example')
            ->assertSee('Riwayat tagihan terbaru')
            ->assertSee('Unpaid')
            ->assertSee('Detail invoice')
            ->assertSee(route('invoices.show', $invoice));
    }

    public function test_terminating_pppoe_customer_disables_secret_and_disconnects_session(): void
    {
        $router = Router::query()->create([
            'name' => 'Termination router',
            'host' => '192.0.2.22',
            'port' => 8728,
            'username' => 'test-user',
            'password' => 'test-password',
        ]);
        $customer = $this->customer($router, 'ppp-terminate', 'Customer to terminate', 'active');

        $routerOs = Mockery::mock(RouterOsService::class);
        $routerOs->shouldReceive('findPppSecret')->once()->withArgs(fn ($r, $username) => $r->id === $router->id && $username === 'ppp-terminate')
            ->andReturn([['.id' => '*1', 'name' => 'ppp-terminate', 'service' => 'pppoe', 'profile' => 'ISOLIR']]);
        $routerOs->shouldReceive('enablePppSecret')->once()->withArgs(fn ($r, $username, $enabled) => $r->id === $router->id && $username === 'ppp-terminate' && $enabled === false);
        $routerOs->shouldReceive('disconnectPppActive')->once()->withArgs(fn ($r, $username) => $r->id === $router->id && $username === 'ppp-terminate');
        $this->app->instance(RouterOsService::class, $routerOs);
        $this->loginAdmin();

        $this->patch(route('customers.terminate', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'terminated']);
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
