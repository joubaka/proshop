<?php

return [
    'enabled' => env('LIGHTS_ENABLED', false),
    // Loopback shortcut from the legacy WAMP login to the isolated Lights acceptance server.
    'local_acceptance_url' => env('LIGHTS_LOCAL_ACCEPTANCE_URL', 'http://127.0.0.1:8097/lights/login'),
    // Set to live only in a dedicated deployment after payment and hardware acceptance.
    'mode' => env('LIGHTS_MODE', 'simulation'),
    'max_session_seconds' => 14400,
    // Venue-wide hard closing boundary, independent of the shop application's timezone.
    'cutoff_timezone' => env('LIGHTS_CUTOFF_TIMEZONE', 'Africa/Johannesburg'),
    'cutoff_dispatch_buffer_seconds' => 10,
    'require_verified_email' => env('LIGHTS_REQUIRE_VERIFIED_EMAIL', true),
    'terms_version' => '2026-09-03',
    'support_email' => env('LIGHTS_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS')),
    // Local, admin-only commissioning form; never enables relay control.
    'shelly_setup' => false,
    'shelly' => [
        // Production secret. Never place the cloud authorization key in source control.
        'auth_key' => env('LIGHTS_SHELLY_AUTH_KEY'),
    ],
    // Emergency deployment kill switches. Admin commands remain authenticated and timed.
    'control' => [
        'live_enabled' => env('LIGHTS_SHELLY_LIVE_ENABLED', false),
        'customer_enabled' => env('LIGHTS_CUSTOMER_CONTROL_ENABLED', false),
        // Acceptance-only convenience. Production never consults this setting.
        'local_approval_required' => true,
        'rate_cents' => env('LIGHTS_RATE_CENTS', 6000),
        'max_seconds' => env('LIGHTS_PILOT_MAX_SECONDS', 60),
    ],
    'payfast' => [
        'enabled' => env('LIGHTS_PAYFAST_ENABLED', false),
        'sandbox' => env('LIGHTS_PAYFAST_SANDBOX', true),
        'merchant_id' => env('LIGHTS_PAYFAST_MERCHANT_ID'),
        'merchant_key' => env('LIGHTS_PAYFAST_MERCHANT_KEY'),
        'passphrase' => env('LIGHTS_PAYFAST_PASSPHRASE'),
        'process_url' => env('LIGHTS_PAYFAST_SANDBOX', true)
            ? 'https://sandbox.payfast.co.za/eng/process'
            : 'https://www.payfast.co.za/eng/process',
        'validate_url' => env('LIGHTS_PAYFAST_SANDBOX', true)
            ? 'https://sandbox.payfast.co.za/eng/query/validate'
            : 'https://www.payfast.co.za/eng/query/validate',
        'source_cidrs' => [
            '197.97.145.144/28', '41.74.179.192/27', '102.216.36.0/28',
            '102.216.36.128/28', '144.126.193.139/32',
        ],
    ],
];
