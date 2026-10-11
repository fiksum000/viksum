<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CustomerExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_export_filtered_customers_as_a_valid_xlsx_without_passwords(): void
    {
        $finance = User::create([
            'name' => 'Finance',
            'email' => 'finance-customer-export@example.test',
            'password' => 'strong-test-password',
            'role' => 'finance',
        ]);
        $package = Package::create(['name' => 'Paket Hotspot', 'price' => 100000, 'normal_profile' => 'HS-5M']);

        Customer::create([
            'customer_code' => 'HS-EXPORT-001',
            'name' => '=Formula safe customer',
            'service_type' => 'hotspot',
            'status' => 'active',
            'due_day' => 10,
            'package_id' => $package->id,
            'hotspot_username' => 'hotspot-export-user',
            'hotspot_password' => 'do-not-export-this-secret',
        ]);
        Customer::create([
            'customer_code' => 'PPP-EXPORT-002',
            'name' => 'PPPoE customer',
            'service_type' => 'pppoe',
            'status' => 'active',
            'due_day' => 10,
            'package_id' => $package->id,
            'pppoe_username' => 'pppoe-export-user',
            'pppoe_password' => 'another-do-not-export-secret',
        ]);

        $response = $this->withSession(['user_id' => $finance->id])
            ->get(route('customers.export', ['service_type' => 'hotspot', 'status' => 'active']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertDownload('customers.xlsx');

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray();
        $this->assertSame('customer_code', $rows[0][0]);
        $this->assertSame('hotspot_username', $rows[0][23]);
        $this->assertSame('hotspot-export-user', $rows[1][23]);
        $this->assertStringStartsWith('=Formula safe customer', $rows[1][1]);
        $this->assertCount(2, $rows);
        $this->assertNotContains('pppoe_password', $rows[0]);
        $this->assertNotContains('hotspot_password', $rows[0]);
        $this->assertNotContains('do-not-export-this-secret', array_merge(...$rows));
        $spreadsheet->disconnectWorksheets();
    }

    public function test_admin_can_import_hotspot_customers_from_billing_csv_export_format(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-customer-import@example.test',
            'password' => 'strong-test-password',
            'role' => 'admin',
        ]);
        $package = Package::create(['name' => 'Paket Hotspot', 'price' => 100000, 'normal_profile' => 'HS-5M']);

        $csv = implode(',', ['customer_code', 'name', 'service_type', 'status', 'package', 'hotspot_username'])."\n"
            .implode(',', ['HS-IMPORT-001', 'Hotspot Test', 'hotspot', 'active', $package->name, 'hotspot-import-user'])."\n";
        $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->withSession(['user_id' => $admin->id])
            ->post(route('customers.import'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customers', [
            'customer_code' => 'HS-IMPORT-001',
            'service_type' => 'hotspot',
            'hotspot_username' => 'hotspot-import-user',
            'hotspot_profile' => 'default',
            'pppoe_username' => null,
            'package_id' => $package->id,
        ]);
    }
}
