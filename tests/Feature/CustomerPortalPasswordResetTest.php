<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_portal_password_for_customer_without_existing_password(): void
    {
        $admin = $this->user('super_admin');
        $customer = $this->customer();

        $response = $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.portal-password.reset', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('success')
            ->assertSessionHas('portal_password_created');

        $password = (string) $response->getSession()->get('portal_password_created');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $password);

        $customer->refresh();
        $this->assertTrue(Hash::check($password, $customer->portal_password));
        $this->assertSame($customer->customer_code, $customer->fresh()->customer_code);
    }

    public function test_portal_password_reset_invalidates_the_previous_password_and_shows_new_password_once(): void
    {
        $admin = $this->user('admin');
        $customer = $this->customer('Known-old-portal-password');

        $response = $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.portal-password.reset', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('portal_password_created');

        $password = (string) $response->getSession()->get('portal_password_created');
        $customer->refresh();

        $this->assertTrue(Hash::check($password, $customer->portal_password));
        $this->assertFalse(Hash::check('Known-old-portal-password', $customer->portal_password));

        $firstDetail = $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Password diatur')
            ->assertSee('Ulangi password portal')
            ->assertSee($password);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee($password);

        $this->assertSame(200, $firstDetail->getStatusCode());
    }

    public function test_operator_cannot_reset_customer_portal_password(): void
    {
        $operator = $this->user('operator');
        $customer = $this->customer();

        $this->withSession(['user_id' => $operator->id])
            ->post(route('customers.portal-password.reset', $customer))
            ->assertForbidden();

        $customer->refresh();
        $this->assertNull($customer->portal_password);
    }

    public function test_admin_can_see_portal_setup_action_for_imported_customer_without_password(): void
    {
        $admin = $this->user('admin');
        $customer = $this->customer();

        $this->withSession(['user_id' => $admin->id])
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Portal pelanggan')
            ->assertSee('Belum diatur')
            ->assertSee('Buat password portal')
            ->assertSee(route('customers.portal-password.reset', $customer));
    }

    private function customer(?string $portalPassword = null): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'PORTAL-RESET-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Portal password test customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'portal_password' => $portalPassword,
        ]);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' Portal Password Admin',
            'email' => 'portal-reset-'.$role.'-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => 'strong-test-password',
            'role' => $role,
        ]);
    }
}
