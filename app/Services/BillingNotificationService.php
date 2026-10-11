<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\BillingNotification;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

class BillingNotificationService
{
    /**
     * Build the standard invoice reminder with aliases used by this application
     * and by legacy single-brace WhatsApp templates.
     *
     * @return array{0:string,1:array<string,string>}
     */
    public function billingReminderPayload(Invoice $invoice, Customer $customer, string $daysOffset = 'manual'): array
    {
        $customer->loadMissing(['package', 'hotspotProfile']);

        $timezone = config('billing.timezone');
        $dueDate = CarbonImmutable::parse($invoice->due_date, $timezone);
        $amount = number_format((int) $invoice->total, 0, ',', '.');
        $paymentUrl = $invoice->payment_url ?: route('public.pay', $invoice->public_token);
        $serviceUsername = $customer->service_type === 'pppoe'
            ? $customer->pppoe_username
            : $customer->hotspot_username;
        $profileName = $customer->package?->name
            ?: $customer->hotspotProfile?->name
            ?: $customer->pppoe_profile_normal
            ?: strtoupper($customer->service_type);
        $expiredAt = $dueDate->format('d-m-Y');
        $nextBill = 'Rp '.$amount;

        $variables = [
            // Existing application placeholders.
            'name' => $customer->name,
            'customer_code' => $customer->customer_code,
            'portal_username' => $customer->customer_code,
            'service_username' => (string) $serviceUsername,
            'pppoe_username' => (string) $customer->pppoe_username,
            'portal_url' => route('portal.login'),
            'invoice_number' => $invoice->invoice_number,
            'amount' => $amount,
            'due_date' => $expiredAt,
            'payment_url' => $paymentUrl,
            'days_offset' => $daysOffset,
            // Aliases from the requested invoice message format.
            'invoice_code' => $invoice->invoice_number,
            'profile_name' => (string) $profileName,
            'user_code' => $customer->customer_code,
            'next_bill' => $nextBill,
            'expired_at' => $expiredAt,
            'link_payment' => $paymentUrl,
        ];

        $message = "Pesan otomatis. Nomor ini tidak bisa menerima telepon.\n\n"
            ."Tagihan Baru\n\n"
            ."Invoice No: {$variables['invoice_code']}\n"
            ."Product: {$variables['profile_name']}\n"
            ."Status: Belum Dibayar\n"
            ."Device: {$variables['name']} - {$variables['user_code']}\n\n"
            ."Jumlah Tagihan: {$variables['next_bill']}\n"
            ."Tanggal Jatuh Tempo: {$variables['expired_at']}\n\n"
            ."Anda dapat melihat rincian dan melakukan pembayaran melalui tautan berikut:\n"
            ."{$variables['link_payment']}\n\n"
            ."Nomor ini tidak bisa menerima pesan atau telepon.\n\n"
            ."KELUHAN DAN SARAN CHAT 0859102797611\n\n"
            ."Mohon segera lakukan pembayaran sebelum tanggal jatuh tempo agar Anda tetap dapat menikmati layanan kami. Terima kasih.\n\n"
            ."Salam hormat,\nPT Usaha Adi Sanggoro";

        return [$message, $variables];
    }

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

