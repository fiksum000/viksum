<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_be_archived_without_deleting_invoice_history_or_touching_router(): void
    {
        $customer = Customer::query()->create([
            'customer_code' => '728828001',
            'name' => 'Archive test',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 10,
            'pppoe_username' => 'archive-test-user',
        ]);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-ARCHIVE-0001',
            'public_token' => str_repeat('a', 96),
            'customer_id' => $customer->id,
            'period' => '2026-10',
            'issued_at' => '2026-10-01',
            'due_date' => '2026-10-10',
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'unpaid',
        ]);
        $admin = User::query()->create([
            'name' => 'Test administrator',
            'email' => 'archive-admin@example.test',
            'password' => 'test-password',
            'role' => 'super_admin',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'customer_id' => $customer->id]);
        $this->assertSame($customer->id, $invoice->fresh()->customer->id);
        $this->assertNull(Customer::query()->find($customer->id));
    }

    public function test_operator_can_archive_customers(): void
    {
        $customer = Customer::query()->create([
            'customer_code' => '728828002',
            'name' => 'Archive permission test',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 10,
        ]);
        $operator = User::query()->create([
            'name' => 'Test operator',
            'email' => 'operator@example.test',
            'password' => 'test-password',
            'role' => 'operator',
        ]);

        $this->withSession(['user_id' => $operator->id])
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }
}
