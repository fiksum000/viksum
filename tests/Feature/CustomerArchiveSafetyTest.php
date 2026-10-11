<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerArchiveSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_terminated_customer_can_be_archived_and_invoice_history_is_preserved(): void
    {
        $customer = $this->customer('terminated', 'Former customer');
        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-ARCHIVE-SAFE-001',
            'public_token' => str_repeat('a', 48),
            'customer_id' => $customer->id,
            'period' => '2026-10',
            'issued_at' => '2026-10-01',
            'due_date' => '2026-10-10',
            'subtotal' => 100000,
            'total' => 100000,
            'status' => 'unpaid',
        ]);
        $operator = $this->user('operator', 'archive-operator@example.test');

        $this->withSession(['user_id' => $operator->id])
            ->post(route('customers.archive', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-ARCHIVE-SAFE-001',
        ]);
        $archivedInvoice = $invoice->fresh();
        $this->assertNotNull($archivedInvoice->customer);
        $this->assertSame($customer->id, $archivedInvoice->customer->id);
        $this->assertTrue($archivedInvoice->customer->trashed());
    }

    public function test_active_customer_cannot_be_archived(): void
    {
        $customer = $this->customer('active', 'Active customer must remain visible');
        $admin = $this->user('admin', 'archive-admin@example.test');

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.archive', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'active',
            'deleted_at' => null,
        ]);
    }

    public function test_archiving_releases_onu_but_does_not_delete_financial_history(): void
    {
        $olt = Olt::query()->create(['name' => 'Archive test OLT']);
        $customer = $this->customer('terminated', 'Former ONU customer');
        $onu = Onu::query()->create([
            'olt_id' => $olt->id,
            'customer_id' => $customer->id,
            'pon_port' => '1/1',
            'onu_id' => '7',
            'status' => 'unknown',
        ]);
        $admin = $this->user('super_admin', 'archive-super@example.test');

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.archive', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('onus', ['id' => $onu->id, 'customer_id' => null]);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_archived_customer_is_hidden_from_active_customer_list(): void
    {
        $customer = $this->customer('terminated', 'Archived customer should not be listed');
        $admin = $this->user('admin', 'archive-list-admin@example.test');

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.archive', $customer))
            ->assertRedirect(route('customers.index'));

        $this->withSession(['user_id' => $admin->id])
            ->get(route('customers.index'))
            ->assertOk()
            ->assertDontSee('Archived customer should not be listed');
    }

    private function customer(string $status, string $name): Customer
    {
        return Customer::query()->create([
            'customer_code' => 'ARCHIVE-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => $name,
            'service_type' => 'pppoe',
            'status' => $status,
            'due_day' => 20,
        ]);
    }

    private function user(string $role, string $email): User
    {
        return User::query()->create([
            'name' => 'Archive test '.$role,
            'email' => $email,
            'password' => 'strong-test-password',
            'role' => $role,
        ]);
    }
}
