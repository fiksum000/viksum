<?php

namespace App\Http\Controllers;

use App\Models\PaymentSetting;
use App\Support\Audit;
use App\Services\TripayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentSettingsController
{
    public function index()
    {
        $settings = PaymentSetting::query()->first();

        return view('payments.settings', [
            'settings' => $settings,
            'hasTripayCredentials' => (bool) ($settings?->tripay_api_key && $settings?->tripay_private_key && $settings?->tripay_merchant_code)
                || (bool) (config('services.tripay.api_key') && config('services.tripay.private_key') && config('services.tripay.merchant_code')),
            'qrUrl' => $settings?->dana_qr_path ? Storage::disk('public')->url($settings->dana_qr_path) : null,
            'merchantLogoUrl' => $settings?->merchant_logo_path ? Storage::disk('public')->url($settings->merchant_logo_path) : null,
            'hasDemoPassword' => (bool) $settings?->demo_password,
        ]);
    }

    public function update(Request $request)
    {
        $settings = PaymentSetting::query()->first() ?? new PaymentSetting();
        $data = $request->validate([
            'tripay_enabled' => ['required', 'boolean'],
            'tripay_mode' => ['required', 'in:sandbox,production'],
            'tripay_api_key' => ['nullable', 'string', 'max:2000'],
            'tripay_private_key' => ['nullable', 'string', 'max:2000'],
            'tripay_merchant_code' => ['nullable', 'string', 'max:100'],
            'tripay_callback_url' => ['nullable', 'url', 'max:2048'],
            'tripay_return_url' => ['nullable', 'url', 'max:2048'],
            'tripay_whitelist_ips' => ['nullable', 'string', 'max:4000'],
            'settlement_account_name' => ['nullable', 'string', 'max:150'],
            'settlement_bank_name' => ['nullable', 'string', 'max:100'],
            'settlement_account_number' => ['nullable', 'string', 'max:100'],
            'merchant_name' => ['nullable', 'string', 'max:150'],
            'merchant_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'merchant_website' => ['nullable', 'url', 'max:2048'],
            'demo_username' => ['nullable', 'string', 'max:120'],
            'demo_password' => ['nullable', 'string', 'max:1024'],
            'demo_isolation_date' => ['nullable', 'date'],
            'merchant_description' => ['nullable', 'string', 'max:4000'],
            'dana_enabled' => ['required', 'boolean'],
            'dana_account_name' => ['nullable', 'string', 'max:120'],
            'dana_phone' => ['nullable', 'string', 'max:40'],
            'dana_qr' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ]);

        if ($data['tripay_enabled']) {
            $hasApi = filled($data['tripay_api_key'] ?? null) || filled($settings->tripay_api_key) || filled(config('services.tripay.api_key'));
            $hasPrivate = filled($data['tripay_private_key'] ?? null) || filled($settings->tripay_private_key) || filled(config('services.tripay.private_key'));
            $hasMerchant = filled($data['tripay_merchant_code'] ?? null) || filled($settings->tripay_merchant_code) || filled(config('services.tripay.merchant_code'));
            if (! ($hasApi && $hasPrivate && $hasMerchant)) {
                throw ValidationException::withMessages(['tripay_enabled' => 'Tripay belum dapat diaktifkan: Merchant Code, API Key, dan Private Key wajib lengkap.']);
            }
        }
        if ($data['dana_enabled'] && ! ($request->hasFile('dana_qr') || filled($settings->dana_qr_path))) {
            throw ValidationException::withMessages(['dana_qr' => 'Upload QRIS DANA sebelum mengaktifkannya.']);
        }

        $settings->tripay_enabled = $data['tripay_enabled'];
        $settings->tripay_mode = $data['tripay_mode'];
        $settings->tripay_merchant_code = $data['tripay_merchant_code'] ?: null;
        $settings->tripay_callback_url = $data['tripay_callback_url'] ?: null;
        $settings->tripay_return_url = $data['tripay_return_url'] ?: null;
        $settings->tripay_whitelist_ips = $data['tripay_whitelist_ips'] ?: null;
        $settings->settlement_account_name = $data['settlement_account_name'] ?: null;
        $settings->settlement_bank_name = $data['settlement_bank_name'] ?: null;
        $settings->merchant_name = $data['merchant_name'] ?: null;
        $settings->merchant_website = $data['merchant_website'] ?: null;
        $settings->demo_username = $data['demo_username'] ?: null;
        $settings->demo_isolation_date = $data['demo_isolation_date'] ?: null;
        $settings->merchant_description = $data['merchant_description'] ?: null;
        $settings->dana_enabled = $data['dana_enabled'];
        $settings->dana_account_name = $data['dana_account_name'] ?: null;
        $settings->dana_phone = $data['dana_phone'] ?: null;
        $settings->updated_by = $request->session()->get('user_id');

        // Blank secret inputs mean “keep the current encrypted value”.
        foreach (['tripay_api_key', 'tripay_private_key', 'settlement_account_number', 'demo_password'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $settings->{$secret} = trim($data[$secret]);
            }
        }

        if ($request->hasFile('dana_qr')) {
            $settings->dana_qr_path = $request->file('dana_qr')->store('payment-qr', 'public');
        }
        if ($request->hasFile('merchant_logo')) {
            $settings->merchant_logo_path = $request->file('merchant_logo')->store('merchant-logos', 'public');
        }

        $settings->save();
        Cache::forget('tripay.channels.sandbox');
        Cache::forget('tripay.channels.production');
        Audit::log('payment.settings_updated', PaymentSetting::class, $settings->id, [
            'tripay_enabled' => $settings->tripay_enabled,
            'tripay_mode' => $settings->tripay_mode,
            'merchant_name' => $settings->merchant_name,
            'dana_enabled' => $settings->dana_enabled,
        ]);

        return back()->with('success', 'Pengaturan pembayaran berhasil disimpan. Secret Tripay tersimpan terenkripsi.');
    }
}
