<?php

return [
    // The public storefront stays unavailable until a reviewed channel is configured and enabled.
    'enabled' => (bool) env('SHOP_ENABLED', false),
    'channel' => env('SHOP_CHANNEL', 'main'),
    'products_per_page' => (int) env('SHOP_PRODUCTS_PER_PAGE', 24),
    'cart_lifetime_minutes' => (int) env('SHOP_CART_LIFETIME_MINUTES', 10080),
    'reservation_minutes' => (int) env('SHOP_RESERVATION_MINUTES', 20),
];
