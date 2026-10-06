<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Onu extends Model
{
    protected $fillable = ['olt_id', 'customer_id', 'name', 'pon_port', 'onu_id', 'serial_number', 'mac_address', 'rx_power', 'tx_power', 'temperature', 'uptime', 'status', 'last_seen_at', 'metadata'];

    protected $casts = ['rx_power' => 'decimal:2', 'tx_power' => 'decimal:2', 'temperature' => 'decimal:2', 'last_seen_at' => 'datetime', 'metadata' => 'array'];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
