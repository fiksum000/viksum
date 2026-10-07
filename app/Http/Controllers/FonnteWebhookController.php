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

        // Fonnte's message-status callback sends an id/status and optionally stateid/state.
        if ((is_scalar($data['id'] ?? null) || is_scalar($data['stateid'] ?? null)) && (isset($data['status']) || isset($data['state']))) {
            $providerId = is_scalar($data['id'] ?? null) ? substr((string) $data['id'], 0, 120) : null;
            $status = substr((string) ($data['status'] ?? ''), 0, 60);
            $state = is_scalar($data['state'] ?? null) ? substr((string) $data['state'], 0, 120) : null;
            $stateId = is_scalar($data['stateid'] ?? null) ? substr((string) $data['stateid'], 0, 120) : null;
            $normalized = strtolower(trim($status));
            $deliveryStatus = in_array($normalized, ['invalid', 'failed', 'expired', 'url unreachable'], true) ? 'failed'
                : (in_array($normalized, ['sent', 'delivered', 'read'], true) ? 'sent' : 'queued');
            $log = $providerId
                ? WaLog::query()->where('provider_message_id', $providerId)->first()
                : WaLog::query()->where('provider_state_id', $stateId)->first();

            if (! $log) {
                $log = new WaLog([
                    'target' => substr((string) ($data['target'] ?? $data['device'] ?? 'unknown'), 0, 255),
                    'event' => 'delivery_status',
                    'message' => 'Status pengiriman diperbarui oleh Fonnte.',
                ]);
            }

            $log->provider_message_id = $providerId ?: $log->provider_message_id;
            $log->provider_status = $status;
            $log->provider_state = $state;
            $log->provider_state_id = $stateId;
            $log->status = $deliveryStatus;
            $log->response = array_filter(['device' => $data['device'] ?? null, 'status' => $status, 'state' => $state, 'stateid' => $stateId]);
            $log->save();

            $billingNotification = $log->billingNotification;
            if ($billingNotification) {
                $billingNotification->update([
                    'status' => $deliveryStatus,
                    'sent_at' => $deliveryStatus === 'sent' ? ($billingNotification->sent_at ?? now()) : null,
                    'last_error' => $deliveryStatus === 'failed' ? ('Status Fonnte: '.($state ?: $status)) : null,
                ]);
            }

            return response()->json(['success' => true]);
        }

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

