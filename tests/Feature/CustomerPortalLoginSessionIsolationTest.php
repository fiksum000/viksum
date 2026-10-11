<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPortalLoginSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_login_does_not_keep_an_existing_admin_session(): void
    {
        $admin = User::query()->create([
            'name' => 'Existing admin session',
            'email' => 'portal-session-admin@example.test',
            'password' => 'strong-test-password',
            'role' => 'super_admin',
        ]);

        $customer = Customer::query()->create([
            'customer_code' => 'PORTAL-SESSION-001',
            'name' => 'Portal session customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'portal_password' => 'portal-test-password',
        ]);

        $this->withSession([
            'user_id' => $admin->id,
            'sensitive_stale_value' => 'must-be-cleared',
        ])->post(route('portal.login.submit'), [
            'customer_code' => $customer->customer_code,
            'password' => 'portal-test-password',
        ])
            ->assertRedirect(route('portal.home'))
            ->assertSessionHas('customer_id', $customer->id)
            ->assertSessionMissing('user_id')
            ->assertSessionMissing('sensitive_stale_value');
    }
}
