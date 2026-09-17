<?php

return [
    // The public storefront stays unavailable until a reviewed channel is configured and enabled.
    'enabled' => (bool) env('SHOP_ENABLED', false),
    'channel' => env('SHOP_CHANNEL', 'main'),
    'products_per_page' => (int) env('SHOP_PRODUCTS_PER_PAGE', 24),
];
