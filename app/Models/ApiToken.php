<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'abilities', 'expires_at', 'last_used_at'];
    protected $hidden = ['token_hash'];
    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
