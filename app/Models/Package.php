<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'name',
        'price',
        'router_id',
        'normal_profile',
        'normal_speed',
        'upload_speed',
        'download_speed',
        'burst_enabled',
        'burst_limit',
        'burst_threshold',
        'burst_time',
        'priority',
        'fup_enabled',
        'fup_limit_bytes',
        'fup_speed_after',
        'fup_upload_speed',
        'fup_download_speed',
        'legacy_normal_profile',
        'legacy_fup_profile',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    protected $casts = [
        'price' => 'integer',
        'burst_enabled' => 'boolean',
        'priority' => 'integer',
        'fup_enabled' => 'boolean',
        'fup_limit_bytes' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function routerProfileName(): string
    {
        return 'VIKSUM-PPP-'.$this->getKey();
    }

    public function routerFupProfileName(): string
    {
        return $this->routerProfileName().'-FUP';
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
