<?php
return [
    'tripay' => [
        'mode' => env('TRIPAY_MODE', 'sandbox'),
        'api_key' => env('TRIPAY_API_KEY'),
        'private_key' => env('TRIPAY_PRIVATE_KEY'),
        'merchant_code' => env('TRIPAY_MERCHANT_CODE'),
        'default_method' => env('TRIPAY_DEFAULT_METHOD', 'QRIS'),
        'callback_url' => env('TRIPAY_CALLBACK_URL'),
        'return_url' => env('TRIPAY_RETURN_URL'),
        'expiry_minutes' => (int) env('TRIPAY_EXPIRY_MINUTES', 1440),
    ],
    'fonnte' => [
        'url' => env('FONNTE_API_URL', 'https://api.fonnte.com/send'),
        'token' => env('FONNTE_TOKEN'),
        'delay' => (int) env('FONNTE_DELAY', 2),
    ],
];
