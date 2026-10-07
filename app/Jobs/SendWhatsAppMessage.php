<?php

namespace App\Jobs;

use App\Services\FonnteService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\BillingNotification;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public ?int $customerId,
        public string $target,
        public string $message,
        public string $event = 'manual',
        public array $variables = [],
        public ?int $billingNotificationId = null,
    ) {
    }

    public function handle(FonnteService $fonnte): void
    {
        $fonnte->send($this->customerId, $this->target, $this->message, $this->event, $this->variables, $this->billingNotificationId);
        if ($this->billingNotificationId) {
            BillingNotification::query()->whereKey($this->billingNotificationId)->where('status', '!=', 'failed')->update([
                'status' => 'sent', 'sent_at' => now(), 'last_error' => null,
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->billingNotificationId) {
            BillingNotification::query()->whereKey($this->billingNotificationId)->update([
                'status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}

