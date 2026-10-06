<?php

namespace App\Jobs;

use App\Services\FonnteService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    ) {
    }

    public function handle(FonnteService $fonnte): void
    {
        $fonnte->send($this->customerId, $this->target, $this->message, $this->event, $this->variables);
    }
}
