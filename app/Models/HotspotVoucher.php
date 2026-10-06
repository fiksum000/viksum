<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotspotVoucher extends Model
{
    protected $fillable = ['router_id', 'created_by', 'username', 'password', 'profile', 'comment', 'status', 'printed_at'];
    protected $hidden = ['password'];
    protected $casts = ['password' => 'encrypted', 'printed_at' => 'datetime'];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
