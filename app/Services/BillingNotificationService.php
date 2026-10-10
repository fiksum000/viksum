<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\BillingNotification;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

class BillingNotificationService
{
    public function recordFailure(Invoice $invoice, string $event, CarbonInterface|string|null $scheduledFor, string $reason): void
    {
        $date = $scheduledFor instanceof CarbonInterface ? $scheduledFor->toDateString() : ($scheduledFor ?: now(config('billing.timezone'))->toDateString());
        $notification = BillingNotification::query()->firstOrCreate(
            ['invoice_id' => $invoice->id, 'event' => $event, 'scheduled_for' => $date],
            ['customer_id' => $invoice->customer_id, 'status' => 'failed', 'last_error' => mb_substr($reason, 0, 1000)],
        );

        if (! $notification->wasRecentlyCreated && $notification->status !== 'sent') {
            $notification->update(['status' => 'failed', 'last_error' => mb_substr($reason, 0, 1000)]);
        }
    }

    /**
     * Retry an existing failed notification by its primary key.
     * This avoids creating a duplicate notification when the saved date key or
     * a concurrent worker makes the generic idempotent queue lookup ambiguous.
     */
    public function retryFailed(
        BillingNotification $notification,
        string $target,
        string $message,
        array $variables = [],
    ): bool {
        $retry = DB::transaction(function () use ($notification): ?array {
            $locked = BillingNotification::query()->whereKey($notification->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'failed') {
                return null;
            }

            $locked->update(['status' => 'queued', 'last_error' => null]);

            return [
                'id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'event' => $locked->event,
            ];
        });

        if (! $retry) {
            return false;
        }

        try {
            SendWhatsAppMessage::dispatch(
                $retry['customer_id'],
                $target,
                $message,
                $retry['event'],
                $variables,
                $retry['id'],
            );
        } catch (Throwable $exception) {
            BillingNotification::query()->whereKey($retry['id'])->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            Log::error('Failed to re-queue billing WhatsApp notification', [
                'billing_notification_id' => $retry['id'],
                'exception' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return true;
    }

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

