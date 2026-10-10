<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkInventorySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_cannot_be_deleted_while_a_customer_uses_it(): void
    {
        $admin = $this->loginAdmin();
        $router = Router::query()->create([
            'name' => 'Customer router',
            'host' => '192.0.2.70',
            'port' => 8728,
            'username' => 'inventory-test',
            'password' => 'test-only-password',
            'enabled' => true,
        ]);
        Customer::query()->create([
            'customer_code' => 'INVENTORY-ROUTER-01',
            'name' => 'Router customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'router_id' => $router->id,
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->delete(route('routers.destroy', $router))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('routers', ['id' => $router->id]);
    }

    public function test_olt_cannot_be_deleted_while_it_has_onu_inventory(): void
    {
        $admin = $this->loginAdmin();
        $olt = Olt::query()->create([
            'name' => 'OLT with inventory',
            'management_protocol' => 'manual',
            'enabled' => true,
            'status' => 'unknown',
        ]);
        Onu::query()->create([
            'olt_id' => $olt->id,
            'pon_port' => '1/1/1',
            'onu_id' => '12',
            'status' => 'unknown',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->delete(route('olts.destroy', $olt))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('olts', ['id' => $olt->id]);
        $this->assertDatabaseHas('onus', ['olt_id' => $olt->id, 'onu_id' => '12']);
    }

    public function test_onu_assignment_updates_customer_network_fields_and_prevents_duplicate_customer_mapping(): void
    {
        $admin = $this->loginAdmin();
        $olt = Olt::query()->create([
            'name' => 'ONU mapping OLT',
            'management_protocol' => 'manual',
            'enabled' => true,
            'status' => 'unknown',
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'ONU-CUSTOMER-01',
            'name' => 'ONU-linked customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
        ]);
        $payload = [
            'olt_id' => $olt->id,
            'customer_id' => $customer->id,
            'name' => 'ONU 12',
            'pon_port' => '1/1/1',
            'onu_id' => '12',
            'serial_number' => 'SN-ONU-12',
            'status' => 'online',
        ];

        $this->withSession(['user_id' => $admin->id])
            ->post(route('onus.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'olt_id' => $olt->id,
            'olt_name' => 'ONU mapping OLT',
            'pon_port' => '1/1/1',
            'onu_id' => '12',
            'onu_sn' => 'SN-ONU-12',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('onus.store'), array_merge($payload, [
                'name' => 'Duplicate ONU assignment',
                'pon_port' => '1/1/2',
                'onu_id' => '13',
            ]))
            ->assertSessionHasErrors('customer_id');

        $this->assertSame(1, Onu::query()->where('customer_id', $customer->id)->count());
    }

    private function loginAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Inventory admin',
            'email' => 'inventory-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);
        $this->withSession(['user_id' => $user->id]);
        return $user;
    }
}
