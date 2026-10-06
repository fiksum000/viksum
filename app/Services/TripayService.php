<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripayService
{
    private function settings(): ?PaymentSetting
    {
        return PaymentSetting::query()->first();
    }

    private function value(string $column, string $configKey): mixed
    {
        $value = $this->settings()?->{$column};
        return filled($value) ? $value : config($configKey);
    }

    private function enabled(): bool
    {
        $settings = $this->settings();
        if ($settings) {
            return $settings->tripay_enabled
                && filled($this->value('tripay_api_key', 'services.tripay.api_key'))
                && filled($this->value('tripay_private_key', 'services.tripay.private_key'))
                && filled($this->value('tripay_merchant_code', 'services.tripay.merchant_code'));
        }

        return filled(config('services.tripay.api_key'))
            && filled(config('services.tripay.private_key'))
            && filled(config('services.tripay.merchant_code'));
    }

    private function baseUrl(): string
    {
        return $this->value('tripay_mode', 'services.tripay.mode') === 'production'
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->value('tripay_api_key', 'services.tripay.api_key'), 'Accept' => 'application/json'];
    }

    public function availableChannels(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $mode = $this->value('tripay_mode', 'services.tripay.mode');
        return Cache::remember('tripay.channels.'.$mode, 300, function (): array {
            $response = Http::timeout(15)->withHeaders($this->headers())->get($this->baseUrl().'/merchant/payment-channel');
            if ($response->failed() || ! ($response->json('success') ?? false)) {
                throw new RuntimeException('Daftar channel Tripay tidak dapat dimuat. Periksa kredensial, mode, dan koneksi server.');
            }

            return collect($response->json('data', []))
                ->filter(fn ($channel) => ($channel['active'] ?? false) === true)
                ->map(fn ($channel) => ['code' => (string) $channel['code'], 'name' => (string) $channel['name']])
                ->values()->all();
        });
    }

    public function assertAvailableChannel(string $method): void
    {
        if (! collect($this->availableChannels())->contains(fn ($channel) => hash_equals($channel['code'], $method))) {
            throw new RuntimeException('Metode pembayaran tidak aktif atau tidak tersedia pada akun Tripay.');
        }
    }

    public function createTransaction(string $method, string $merchantRef, string $customerName, string $customerEmail, int $amount, string $phone = '', ?string $returnUrl = null): array
    {
        $merchant = $this->value('tripay_merchant_code', 'services.tripay.merchant_code');
        $private = $this->value('tripay_private_key', 'services.tripay.private_key');
        if (! $this->enabled()) {
            throw new RuntimeException('Tripay belum aktif atau kredensialnya belum lengkap.');
        }

        $signature = hash_hmac('sha256', $merchant.$merchantRef.$amount, $private);
        $payload = [
            'method' => $method,
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail ?: 'customer@example.com',
            'customer_phone' => $phone,
            'order_items' => [['name' => 'Internet', 'price' => $amount, 'quantity' => 1]],
            'callback_url' => $this->value('tripay_callback_url', 'services.tripay.callback_url') ?: route('tripay.webhook'),
            'return_url' => $this->settings()?->tripay_return_url
                ?: (filled(config('services.tripay.return_url')) && ! str_ends_with(rtrim((string) parse_url(config('services.tripay.return_url'), PHP_URL_PATH), '/'), '/portal')
                    ? config('services.tripay.return_url')
                    : ($returnUrl ?? url('/portal'))),
            'expired_time' => now(config('billing.timezone'))->addMinutes(config('services.tripay.expiry_minutes'))->timestamp,
            'signature' => $signature,
        ];

        $response = Http::timeout(20)->withHeaders($this->headers())->asForm()->post($this->baseUrl().'/transaction/create', $payload);
        if ($response->failed()) {
            throw new RuntimeException('Tripay menolak permintaan transaksi (HTTP '.$response->status().').');
        }
        $json = $response->json();
        if (! ($json['success'] ?? false)) {
            throw new RuntimeException('Tripay: '.($json['message'] ?? 'Transaksi tidak dapat dibuat.'));
        }

        return $json['data'] ?? [];
    }

    public function verifyCallbackSignature(string $raw, ?string $signature): bool
    {
        if (! $signature || ! $this->enabled()) {
            return false;
        }

        $expected = hash_hmac('sha256', $raw, (string) $this->value('tripay_private_key', 'services.tripay.private_key'));
        return hash_equals($expected, $signature);
    }

    public function createInvoiceCheckout(Invoice $invoice, string $method): Payment
    {
        return DB::transaction(function () use ($invoice, $method): Payment {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'unpaid') {
                throw new RuntimeException('Invoice tidak dalam status belum bayar.');
            }

            $existing = $locked->payments()->where('provider', 'tripay')->where('reference', $locked->payment_reference)
                ->whereIn('status', ['unpaid', 'pending'])->whereNotNull('checkout_url')->first();
            if ($existing && $locked->payment_expired_at && $locked->payment_expired_at->isFuture()) {
                return $existing;
            }

            $this->assertAvailableChannel($method);
            $customer = $locked->customer;
            $data = $this->createTransaction($method, $locked->invoice_number, $customer->name, $customer->email ?? '', $locked->total, $customer->phone ?? '', route('public.pay', $locked->public_token));
            $reference = (string) ($data['reference'] ?? '');
            $checkoutUrl = (string) ($data['checkout_url'] ?? '');
            if ($reference === '' || $checkoutUrl === '') {
                throw new RuntimeException('Tripay tidak mengembalikan reference dan checkout URL yang diperlukan.');
            }
            if (isset($data['amount']) && (int) $data['amount'] !== (int) $locked->total) {
                throw new RuntimeException('Nominal transaksi Tripay tidak sama dengan invoice.');
            }

            $payment = Payment::create([
                'invoice_id' => $locked->id, 'provider' => 'tripay', 'reference' => $reference,
                'merchant_ref' => $locked->invoice_number, 'channel' => $method, 'amount' => $locked->total,
                'status' => 'unpaid', 'checkout_url' => $checkoutUrl, 'raw_payload' => $data,
            ]);
            $locked->update([
                'payment_url' => $checkoutUrl,
                'payment_reference' => $reference,
                'payment_expired_at' => isset($data['expired_time']) ? \Illuminate\Support\Carbon::createFromTimestamp($data['expired_time']) : null,
            ]);

            return $payment;
        });
    }
}
