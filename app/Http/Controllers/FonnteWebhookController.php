<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\WaLog;
use Illuminate\Http\Request;

class FonnteWebhookController
{
    public function __invoke(Request $request, string $token)
    {
        $settings = IntegrationSetting::query()->find(1);
        $expected = $settings?->fonnte_webhook_token;
        if (! $settings?->fonnte_enabled || ! $expected || ! hash_equals($expected, $token)) {
            return response()->json(['success' => false], 404);
        }

        $data = $request->all();
        $sender = substr((string) ($data['sender'] ?? $data['device'] ?? 'unknown'), 0, 255);
        $message = $data['message'] ?? null;
        $event = is_string($message) ? 'incoming' : 'device_status';
        $summary = is_scalar($message)
            ? (string) $message
            : (string) ($data['status'] ?? 'Pembaruan status perangkat WhatsApp');

        WaLog::create([
            'customer_id' => is_string($data['sender'] ?? null)
                ? Customer::where('phone', $data['sender'])->value('id')
                : null,
            'target' => $sender,
            'event' => $event,
            'message' => mb_substr($summary, 0, 4000),
            'status' => 'received',
            'response' => array_intersect_key($data, array_flip(['device', 'name', 'timestamp', 'status', 'reason', 'inboxid'])),
        ]);

        return response()->json(['success' => true]);
    }
}
