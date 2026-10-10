<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HotspotVoucher;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_manage_customers_but_cannot_open_finance_or_router_pages(): void
    {
        $operator = $this->user('operator');

        $this->withSession(['user_id' => $operator->id])->get(route('customers.index'))->assertOk();
        $this->withSession(['user_id' => $operator->id])->get(route('invoices.index'))->assertForbidden();
        $this->withSession(['user_id' => $operator->id])->get(route('network.read', ['page' => 'pppoe.secrets']))->assertForbidden();
    }

    public function test_finance_can_open_invoice_pages_but_not_customer_or_network_pages(): void
    {
        $finance = $this->user('finance');

        $this->withSession(['user_id' => $finance->id])->get(route('invoices.index'))->assertOk();
        $this->withSession(['user_id' => $finance->id])->get(route('customers.index'))->assertForbidden();
        $this->withSession(['user_id' => $finance->id])->get(route('network.read', ['page' => 'pppoe.secrets']))->assertForbidden();
    }

    public function test_technician_can_read_network_pages_but_cannot_open_finance_or_manage_vouchers(): void
    {
        $technician = $this->user('technician');
        $router = Router::create([
            'name' => 'Router test',
            'host' => '192.0.2.1',
            'port' => 8728,
            'username' => 'readonly-test',
            'password' => 'not-a-real-router-password',
            'enabled' => true,
        ]);
        HotspotVoucher::create([
            'router_id' => $router->id,
            'username' => 'voucher-test',
            'password' => 'voucher-password-secret',
            'profile' => 'default',
        ]);

        $this->withSession(['user_id' => $technician->id])
            ->get(route('network.read', ['page' => 'pppoe.secrets']))
            ->assertOk();
        $this->withSession(['user_id' => $technician->id])
            ->get(route('invoices.index'))
            ->assertForbidden();

        $this->withSession(['user_id' => $technician->id])
            ->get(route('hotspot.index'))
            ->assertOk()
            ->assertSee('voucher-test')
            ->assertDontSee('voucher-password-secret')
            ->assertDontSee('Buat voucher di MikroTik')
            ->assertDontSee('Cetak voucher terpilih ke PDF');

        $this->withSession(['user_id' => $technician->id])
            ->post(route('hotspot.generate'), ['router_id' => $router->id])
            ->assertForbidden();
    }

    public function test_technician_can_open_customer_detail_but_cannot_edit_or_delete(): void
    {
        $technician = $this->user('technician');
        $customer = Customer::query()->create([
            'customer_code' => 'TECH-CUSTOMER-001',
            'name' => 'Technician detail customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
        ]);

        $list = $this->withSession(['user_id' => $technician->id])
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee(route('customers.show', $customer))
            ->assertSee('Detail')
            ->assertDontSee(route('customers.edit', $customer))
            ->assertDontSee(route('customers.destroy', $customer));

        $this->withSession(['user_id' => $technician->id])
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Technician detail customer')
            ->assertDontSee('Edit pelanggan')
            ->assertDontSee('Hapus');

        $this->withSession(['user_id' => $technician->id])
            ->get(route('customers.edit', $customer))
            ->assertForbidden();
    }

    public function test_admin_can_open_settings_and_super_admin_is_not_assignable_to_regular_admin(): void
    {
        $admin = $this->user('admin');

        $this->withSession(['user_id' => $admin->id])->get(route('users.index'))->assertOk();
        $this->withSession(['user_id' => $admin->id])->get(route('payment-settings.index'))->assertOk();

        $response = $this->withSession(['user_id' => $admin->id])->get(route('users.index'));
        $response->assertDontSee('value="super_admin"', false);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'@example.test',
            'password' => 'strong-test-password',
            'role' => $role,
        ]);
    }
}
