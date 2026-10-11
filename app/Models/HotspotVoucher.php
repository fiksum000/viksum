<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotVoucher extends Model
{
    protected $fillable = [
        'router_id',
        'hotspot_profile_id',
        'created_by',
        'username',
        'password',
        'profile',
        'comment',
        'status',
        'printed_at',
        'first_login_at',
        'expires_at',
        'total_bytes_used',
        'last_bytes_in',
        'last_bytes_out',
        'last_sampled_at',
        'fup_applied',
        'sync_status',
        'sync_error',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'printed_at' => 'datetime',
        'first_login_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_sampled_at' => 'datetime',
        'total_bytes_used' => 'integer',
        'last_bytes_in' => 'integer',
        'last_bytes_out' => 'integer',
        'fup_applied' => 'boolean',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function hotspotProfile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'hotspot_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
