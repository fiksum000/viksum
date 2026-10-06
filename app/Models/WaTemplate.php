<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaTemplate extends Model
{
    protected $fillable = ['event', 'name', 'body', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
