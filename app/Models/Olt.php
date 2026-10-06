<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    protected $fillable = ['name', 'vendor', 'model', 'host', 'management_protocol', 'management_port', 'username', 'password', 'enabled', 'status', 'last_seen_at', 'notes'];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'encrypted', 'enabled' => 'boolean', 'last_seen_at' => 'datetime'];

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class);
    }
}
