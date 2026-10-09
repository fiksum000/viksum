<?php

namespace App\Http\Controllers;

use App\Models\WaTemplate;
use App\Models\IntegrationSetting;
use App\Models\WaLog;
use App\Services\FonnteService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WaTemplateController extends Controller
{
    public function index()
    {
        $settings = IntegrationSetting::query()->find(1);
        return view('whatsapp.index', [
            'templates' => WaTemplate::orderBy('name')->get(),
            'settings' => $settings,
            'hasFonnteToken' => filled($settings?->fonnte_api_token ?: config('services.fonnte.token')),
            'companyLogoUrl' => $settings?->company_logo_path ? Storage::disk('public')->url($settings->company_logo_path) : null,
            'webhookUrl' => $settings?->fonnte_webhook_token ? route('fonnte.webhook', $settings->fonnte_webhook_token) : null,
            'incomingLogs' => WaLog::whereIn('event', ['incoming', 'device_status'])->latest()->limit(20)->get(),
        ]);
    }

    public function updateConnection(Request $request)
    {
        $data = $request->validate([
            'fonnte_enabled' => ['required', 'boolean'],
            'fonnte_account_name' => ['required', 'string', 'min:2', 'max:120'],
            'fonnte_api_token' => ['nullable', 'string', 'max:2000'],
            'rotate_webhook_token' => ['nullable', 'boolean'],
        ]);

        $settings = IntegrationSetting::query()->find(1) ?? new IntegrationSetting(['id' => 1]);
        if ($data['fonnte_enabled'] && blank($data['fonnte_api_token'] ?? null) && blank($settings->fonnte_api_token) && blank(config('services.fonnte.token'))) {
            throw ValidationException::withMessages(['fonnte_api_token' => 'API Key / Token Fonnte wajib diisi sebelum koneksi diaktifkan.']);
        }
        $settings->fonnte_enabled = $data['fonnte_enabled'];
        $settings->fonnte_account_name = $data['fonnte_account_name'];
        $settings->updated_by = session('user_id');
        if (filled($data['fonnte_api_token'] ?? null)) {
            $settings->fonnte_api_token = trim($data['fonnte_api_token']);
        }
        if (! $settings->fonnte_webhook_token || ($data['rotate_webhook_token'] ?? false)) {
            $settings->fonnte_webhook_token = \Illuminate\Support\Str::random(48);
        }
        $settings->save();
        Audit::log('fonnte.connection_updated', IntegrationSetting::class, $settings->id, ['enabled' => $settings->fonnte_enabled]);

        return back()->with('success', 'Koneksi Fonnte berhasil disimpan. Webhook URL dapat disalin ke pengaturan perangkat Fonnte.');
    }

    public function checkConnection(FonnteService $fonnte)
    {
        $result = $fonnte->checkDeviceConnection();

        Audit::log('fonnte.connection_checked', IntegrationSetting::class, 1, ['state' => $result['state']]);

        return back()->with('connection_check', $result);
    }

    public function deleteConnection()
    {
        $settings = IntegrationSetting::query()->find(1);
        if (! $settings) {
            return back()->with('info', 'Belum ada konfigurasi WhatsApp yang tersimpan.');
        }

        $settings->forceFill([
            'fonnte_enabled' => false,
            'fonnte_account_name' => null,
            'fonnte_api_token' => null,
            'fonnte_webhook_token' => null,
            'updated_by' => session('user_id'),
        ])->save();

        Audit::log('fonnte.connection_deleted', IntegrationSetting::class, $settings->id);

        return back()->with('success', 'Koneksi dihapus dan pengiriman WhatsApp dinonaktifkan. Log serta template pesan tetap disimpan.');
    }

    public function update(Request $request, WaTemplate $template)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'body' => 'required|string|max:4000', 'enabled' => 'required|boolean']);
        $template->update($data);
        Audit::log('wa_template.updated', WaTemplate::class, $template->id);
        return back()->with('success', 'Template WhatsApp diperbarui.');
    }

    public function uploadCompanyLogo(Request $request)
    {
        $data = $request->validate([
            'company_logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
        ]);

        $settings = IntegrationSetting::query()->find(1) ?? new IntegrationSetting(['id' => 1]);
        $previousPath = $settings->company_logo_path;
        $path = $data['company_logo']->storePublicly('company-logos', 'public');
        if (! $path) {
            return back()->withErrors(['company_logo' => 'Logo gagal disimpan. Silakan coba lagi.']);
        }

        $settings->company_logo_path = $path;
        $settings->updated_by = session('user_id');
        $settings->save();

        if ($previousPath && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }
        Audit::log('whatsapp.company_logo_uploaded', IntegrationSetting::class, $settings->id);

        return back()->with('success', 'Logo perusahaan berhasil diperbarui. Logo akan ikut pada notifikasi tagihan dan pembayaran.');
    }

    public function deleteCompanyLogo()
    {
        $settings = IntegrationSetting::query()->find(1);
        if (! $settings?->company_logo_path) {
            return back()->with('info', 'Belum ada logo perusahaan yang tersimpan.');
        }

        $path = $settings->company_logo_path;
        $settings->company_logo_path = null;
        $settings->updated_by = session('user_id');
        $settings->save();
        Storage::disk('public')->delete($path);
        Audit::log('whatsapp.company_logo_deleted', IntegrationSetting::class, $settings->id);

        return back()->with('success', 'Logo perusahaan dihapus.');
    }
}

