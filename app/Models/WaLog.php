<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaLog extends Model
{
    protected $fillable = [
        'customer_id', 'billing_notification_id', 'target', 'event', 'message', 'status', 'response',
        'provider_message_id', 'provider_status', 'provider_state', 'provider_state_id',
    ];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function billingNotification(): BelongsTo
    {
        return $this->belongsTo(BillingNotification::class);
    }
}

