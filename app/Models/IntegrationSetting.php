<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = ['fonnte_enabled', 'fonnte_account_name', 'fonnte_api_token', 'fonnte_webhook_token', 'updated_by'];

    protected function casts(): array
    {
        return [
            'fonnte_enabled' => 'boolean',
            'fonnte_api_token' => 'encrypted',
            'fonnte_webhook_token' => 'encrypted',
        ];
    }
}
