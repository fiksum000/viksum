<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\User;
use App\Models\WaLog;
use App\Models\WaTemplate;
use App\Services\FonnteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppCompanyBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_replace_and_remove_company_logo(): void
    {
        Storage::fake('public');
        $admin = User::query()->create([
            'name' => 'Admin Uji',
            'email' => 'admin-logo@example.test',
            'password' => 'test-password',
            'role' => 'admin',
        ]);
        $this->withSession(['user_id' => $admin->id]);

        $this->post(route('whatsapp.company-logo.upload'), [
            'company_logo' => UploadedFile::fake()->image('logo.png', 300, 100),
        ])->assertRedirect();

        $firstPath = IntegrationSetting::query()->firstOrFail()->company_logo_path;
        $this->assertNotEmpty($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->post(route('whatsapp.company-logo.upload'), [
            'company_logo' => UploadedFile::fake()->image('logo-baru.webp', 300, 100),
        ])->assertRedirect();

        $secondPath = IntegrationSetting::query()->firstOrFail()->company_logo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->delete(route('whatsapp.company-logo.delete'))->assertRedirect();
        $this->assertNull(IntegrationSetting::query()->firstOrFail()->company_logo_path);
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_logo_is_attached_to_invoice_events_but_not_broadcasts(): void
    {
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'https://billing.example.test/storage']);
        Storage::forgetDisk('public');
        Storage::disk('public')->put('company-logos/logo.png', 'fake image');
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_api_token' => 'test-secret-token',
            'company_logo_path' => 'company-logos/logo.png',
        ]);
        WaTemplate::query()->create([
            'event' => 'billing_reminder',
            'name' => 'Pengingat tagihan',
            'body' => "Yth. {{name}},\nInvoice {{invoice_number}} sejumlah Rp {{amount}}.",
            'enabled' => true,
        ]);
        Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['message-1']], 200)]);

        app(FonnteService::class)->send(null, '628111111111', 'fallback', 'billing_reminder', [
            'name' => 'Pelanggan Uji',
            'invoice_number' => 'INV-UJI-1',
            'amount' => '150.000',
        ]);

        Http::assertSent(fn (Request $request): bool =>
            $request->data()['url'] === 'https://billing.example.test/storage/company-logos/logo.png'
            && str_contains($request->data()['message'], "\nInvoice INV-UJI-1")
        );

        Http::fake(['https://api.fonnte.com/send' => Http::response(['status' => true], 200)]);
        app(FonnteService::class)->send(null, '628111111111', 'Broadcast uji', 'broadcast');
        Http::assertSent(fn (Request $request): bool => ! array_key_exists('url', $request->data()));
        $this->assertSame(2, WaLog::query()->count());
    }

    public function test_fonnte_renders_single_brace_aliases_for_tagihan_baru_template(): void
    {
        IntegrationSetting::query()->create([
            'fonnte_enabled' => true,
            'fonnte_api_token' => 'test-single-brace-token',
        ]);
        WaTemplate::query()->create([
            'event' => 'billing_reminder',
            'name' => 'Tagihan Baru',
            'body' => "Invoice No: {invoice_code}\nProduct: {profile_name}\nStatus: Belum Dibayar\nDevice: {name} - {user_code}\nJumlah Tagihan: {next_bill}\nTanggal Jatuh Tempo: {expired_at}\n{link_payment}",
            'enabled' => true,
        ]);
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => true, 'id' => ['single-brace-message']], 200),
        ]);

        app(FonnteService::class)->send(null, '628111111111', 'fallback message', 'billing_reminder', [
            'invoice_code' => 'INV-2026-001',
            'profile_name' => 'Internet 20 Mbps',
            'name' => 'Pelanggan Uji',
            'user_code' => 'CUS-001',
            'next_bill' => 'Rp 200.000',
            'expired_at' => '20-10-2026',
            'link_payment' => 'https://billing.example.test/pay/example-token',
        ]);

        Http::assertSent(fn (Request $request): bool =>
            $request->data()['message'] === "Invoice No: INV-2026-001\nProduct: Internet 20 Mbps\nStatus: Belum Dibayar\nDevice: Pelanggan Uji - CUS-001\nJumlah Tagihan: Rp 200.000\nTanggal Jatuh Tempo: 20-10-2026\nhttps://billing.example.test/pay/example-token"
        );
    }

    public function test_logo_upload_rejects_unsupported_file_types(): void
    {
        Storage::fake('public');
        $admin = User::query()->create([
            'name' => 'Admin Uji',
            'email' => 'admin-invalid-logo@example.test',
            'password' => 'test-password',
            'role' => 'admin',
        ]);

        $this->withSession(['user_id' => $admin->id])
            ->from(route('whatsapp.index'))
            ->post(route('whatsapp.company-logo.upload'), [
                'company_logo' => UploadedFile::fake()->create('logo.svg', 20, 'image/svg+xml'),
            ])
            ->assertRedirect(route('whatsapp.index'))
            ->assertSessionHasErrors('company_logo');

        $this->assertDatabaseCount('integration_settings', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}

