<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\BillingNotification;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillingNotificationService
{
    /**
     * Queue one WhatsApp notice per invoice/event/date. This makes scheduled retries
     * safe and gives payment confirmations a permanent per-invoice idempotency key.
     */
    public function queue(
        Invoice $invoice,
        string $event,
        string $target,
        string $message,
        array $variables = [],
        CarbonInterface|string|null $scheduledFor = null,
        bool $oncePerInvoice = false,
    ): bool {
        $date = $oncePerInvoice
            ? '1970-01-01'
            : ($scheduledFor instanceof CarbonInterface
                ? $scheduledFor->toDateString()
                : ($scheduledFor ?: now(config('billing.timezone'))->toDateString()));

        try {
            $notification = BillingNotification::query()->firstOrCreate(
                ['invoice_id' => $invoice->id, 'event' => $event, 'scheduled_for' => $date],
                ['customer_id' => $invoice->customer_id, 'status' => 'queued'],
            );
        } catch (QueryException $exception) {
            // A concurrent scheduler may win the unique-key insert after firstOrCreate's lookup.
            if (BillingNotification::query()->where('invoice_id', $invoice->id)->where('event', $event)->whereDate('scheduled_for', $date)->exists()) {
                return false;
            }

            throw $exception;
        }

        if (! $notification->wasRecentlyCreated) {
            if ($notification->status !== 'failed') {
                return false;
            }

            $notification->update(['status' => 'queued', 'last_error' => null]);
        }

        try {
            SendWhatsAppMessage::dispatch(
                $invoice->customer_id,
                $target,
                $message,
                $event,
                $variables,
                $notification->id,
            );
        } catch (Throwable $exception) {
            $notification->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
            Log::error('Failed to queue billing WhatsApp notification', [
                'billing_notification_id' => $notification->id,
                'exception' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return true;
    }
}

