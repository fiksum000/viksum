<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingNotification extends Model
{
    protected $fillable = [
        'invoice_id', 'customer_id', 'event', 'scheduled_for', 'status', 'sent_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['scheduled_for' => 'date', 'sent_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function waLogs(): HasMany
    {
        return $this->hasMany(WaLog::class);
    }
}

