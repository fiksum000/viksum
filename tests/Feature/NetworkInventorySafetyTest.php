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

    public function test_router_page_exposes_edit_form_and_blank_password_keeps_existing_secret(): void
    {
        $admin = $this->loginAdmin();
        $router = Router::query()->create([
            'name' => 'Editable router',
            'host' => '192.0.2.71',
            'port' => 8728,
            'username' => 'old-api-user',
            'password' => 'keep-this-password',
            'enabled' => true,
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->get(route('routers.index'))
            ->assertOk()
            ->assertSee('Edit data router')
            ->assertSee('router-host-'.$router->id);

        $this->withSession(['user_id' => $admin->id])
            ->put(route('routers.update', $router), [
                'name' => 'Updated router',
                'host' => '192.0.2.72',
                'port' => 8728,
                'username' => 'new-api-user',
                'password' => '',
                'ssl' => 0,
                'enabled' => 1,
                'notes' => 'Changed through the edit form',
                'traffic_interface' => 'ether1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $router->refresh();
        $this->assertSame('Updated router', $router->name);
        $this->assertSame('192.0.2.72', $router->host);
        $this->assertSame('new-api-user', $router->username);
        $this->assertSame('keep-this-password', $router->password);
        $this->assertSame('Changed through the edit form', $router->notes);
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

    public function test_onu_inventory_supports_edit_and_delete_actions(): void
    {
        $admin = $this->loginAdmin();
        $olt = Olt::query()->create([
            'name' => 'ONU actions OLT',
            'management_protocol' => 'manual',
            'enabled' => true,
            'status' => 'unknown',
        ]);
        $customer = Customer::query()->create([
            'customer_code' => 'ONU-ACTIONS-CUSTOMER',
            'name' => 'ONU actions customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
        ]);
        $onu = Onu::query()->create([
            'olt_id' => $olt->id,
            'name' => 'ONU old name',
            'pon_port' => '1/1/1',
            'onu_id' => '4',
            'serial_number' => 'ONU-ACTIONS-SN',
            'status' => 'unknown',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->get(route('onus.index'))
            ->assertOk()
            ->assertSee('Edit')
            ->assertSee(route('onus.update', $onu));

        $this->withSession(['user_id' => $admin->id])
            ->put(route('onus.update', $onu), [
                'olt_id' => $olt->id,
                'customer_id' => $customer->id,
                'name' => 'ONU updated name',
                'pon_port' => '1/1/2',
                'onu_id' => '5',
                'serial_number' => 'ONU-ACTIONS-SN-UPDATED',
                'mac_address' => 'AA:BB:CC:DD:EE:FF',
                'rx_power' => -21.5,
                'tx_power' => 2.1,
                'temperature' => 38,
                'uptime' => '1d2h',
                'status' => 'online',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('onus', [
            'id' => $onu->id,
            'name' => 'ONU updated name',
            'customer_id' => $customer->id,
            'pon_port' => '1/1/2',
            'onu_id' => '5',
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'pon_port' => '1/1/2',
            'onu_id' => '5',
            'onu_sn' => 'ONU-ACTIONS-SN-UPDATED',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->delete(route('onus.destroy', $onu))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('onus', ['id' => $onu->id]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'olt_id' => null,
            'pon_port' => null,
            'onu_id' => null,
            'onu_sn' => null,
        ]);
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
