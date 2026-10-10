<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPortalLogoutSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_logout_invalidates_the_entire_customer_session(): void
    {
        $customer = Customer::query()->create([
            'customer_code' => 'PORTAL-LOGOUT-001',
            'name' => 'Portal logout customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 20,
            'portal_password' => Hash::make('portal-test-password'),
        ]);

        $this->withSession([
            'customer_id' => $customer->id,
            'sensitive_stale_value' => 'must-be-cleared',
        ])->post(route('portal.logout'))
            ->assertRedirect(route('portal.login'))
            ->assertSessionMissing('customer_id')
            ->assertSessionMissing('sensitive_stale_value');
    }
}
