<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'tripay_enabled', 'tripay_mode', 'tripay_api_key', 'tripay_private_key',
        'tripay_merchant_code', 'dana_enabled', 'dana_account_name', 'dana_phone',
        'dana_qr_path', 'tripay_callback_url', 'tripay_return_url', 'tripay_whitelist_ips',
        'settlement_account_name', 'settlement_bank_name', 'settlement_account_number',
        'merchant_name', 'merchant_logo_path', 'merchant_website', 'demo_username',
        'demo_password', 'demo_isolation_date', 'merchant_description', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tripay_enabled' => 'boolean',
            'dana_enabled' => 'boolean',
            'tripay_api_key' => 'encrypted',
            'tripay_private_key' => 'encrypted',
            'settlement_account_number' => 'encrypted',
            'demo_password' => 'encrypted',
            'demo_isolation_date' => 'date',
        ];
    }
}
