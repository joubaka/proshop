<?php

return [
    // The public storefront stays unavailable until a reviewed channel is configured and enabled.
    'enabled' => (bool) env('SHOP_ENABLED', false),
    'checkout_enabled' => (bool) env('SHOP_CHECKOUT_ENABLED', false),
    'channel' => env('SHOP_CHANNEL', 'main'),
    'products_per_page' => (int) env('SHOP_PRODUCTS_PER_PAGE', 24),
    'cart_lifetime_minutes' => (int) env('SHOP_CART_LIFETIME_MINUTES', 10080),
    'reservation_minutes' => (int) env('SHOP_RESERVATION_MINUTES', 20),
    'payfast' => [
        'enabled' => (bool) env('SHOP_PAYFAST_ENABLED', false),
        'sandbox' => (bool) env('SHOP_PAYFAST_SANDBOX', true),
        'merchant_id' => env('SHOP_PAYFAST_MERCHANT_ID'),
        'merchant_key' => env('SHOP_PAYFAST_MERCHANT_KEY'),
        'passphrase' => env('SHOP_PAYFAST_PASSPHRASE'),
        'process_url' => env('SHOP_PAYFAST_SANDBOX', true)
            ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process',
        'validate_url' => env('SHOP_PAYFAST_SANDBOX', true)
            ? 'https://sandbox.payfast.co.za/eng/query/validate' : 'https://www.payfast.co.za/eng/query/validate',
        'source_cidrs' => ['197.97.145.144/28', '41.74.179.192/27', '102.216.36.0/28', '102.216.36.128/28'],
    ],
];
