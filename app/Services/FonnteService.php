<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\WaLog;
use App\Models\WaTemplate;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FonnteService
{
    public function isConfigured(): bool
    {
        $settings = IntegrationSetting::query()->find(1);
        if ($settings) {
            return $settings->fonnte_enabled && filled($settings->fonnte_api_token ?: config('services.fonnte.token'));
        }

        return filled(config('services.fonnte.token'));
    }

    public function queue(?int $customerId, string $target, string $fallbackMessage, string $event = 'manual', array $variables = []): void
    {
        SendWhatsAppMessage::dispatch($customerId, $target, $fallbackMessage, $event, $variables);
    }

    public function send(?int $customerId, string $target, string $message, string $event = 'manual', array $variables = []): array
    {
        $settings = IntegrationSetting::query()->find(1);
        $token = $settings?->fonnte_api_token ?: config('services.fonnte.token');
        if (! $this->isConfigured() || ! $token) {
            throw new RuntimeException('FONNTE_TOKEN belum diisi.');
        }

        $template = WaTemplate::where('event', $event)->where('enabled', true)->first();
        if ($template) {
            $replacements = [];
            foreach ($variables as $key => $value) {
                $replacements['{{'.$key.'}}'] = (string) $value;
            }
            $message = strtr($template->body, $replacements);
        }

        $payload = ['target' => $target, 'message' => $message, 'delay' => (string) config('services.fonnte.delay')];
        $response = Http::timeout(20)
            ->withHeaders(['Authorization' => $token])
            ->asForm()
            ->post(config('services.fonnte.url'), $payload);

        $apiStatus = $response->json('status');
        $ok = $response->successful() && ($apiStatus === null || filter_var($apiStatus, FILTER_VALIDATE_BOOLEAN));
        WaLog::create([
            'customer_id' => $customerId,
            'target' => $target,
            'event' => $event,
            'message' => $message,
            'status' => $ok ? 'sent' : 'failed',
            'response' => $response->json() ?? ['body' => Str::limit($response->body(), 1000)],
        ]);

        if (!$ok) {
            throw new RuntimeException('Fonnte mengembalikan respons gagal.');
        }

        return $response->json() ?? [];
    }
}
