<?php
return [
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'isolation_method' => env('BILLING_ISOLATION_METHOD', 'profile'),
    'isolation_profile' => env('BILLING_ISOLATION_PROFILE', 'ISOLIR'),
    'grace_days' => (int) env('BILLING_DEFAULT_GRACE_DAYS', 5),
    'fup_sample_minutes' => (int) env('BILLING_FUP_SAMPLE_MINUTES', 5),
    'admin_phone' => env('BILLING_ADMIN_PHONE'),
    'tax_rate' => (float) env('BILLING_TAX_RATE', 0),
];
