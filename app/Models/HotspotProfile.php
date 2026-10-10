<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotProfile extends Model
{
    protected $fillable = [
        'router_id',
        'name',
        'download_speed',
        'upload_speed',
        'shared_users',
        'fup_limit_bytes',
        'fup_download_speed',
        'fup_upload_speed',
        'validity_value',
        'validity_unit',
        'starts_on_first_login',
        'bind_mac',
        'enabled',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    protected $casts = [
        'shared_users' => 'integer',
        'fup_limit_bytes' => 'integer',
        'validity_value' => 'integer',
        'starts_on_first_login' => 'boolean',
        'bind_mac' => 'boolean',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * RouterOS profile names are namespaced so this application never silently
     * overwrites a pre-existing profile such as "default".
     */
    public function routerProfileName(): string
    {
        return 'VIKSUM-HS-'.$this->getKey();
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(HotspotVoucher::class, 'hotspot_profile_id');
    }
}
