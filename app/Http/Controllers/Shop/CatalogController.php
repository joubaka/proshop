<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\CatalogService;

class CatalogController extends Controller
{
    public function index(CatalogService $catalog)
    {
        $channel = $catalog->channel();
        $products = $catalog->products($channel);

        return response()->view('shop.catalog.index', compact('channel', 'products'))
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function show(string $slug, CatalogService $catalog)
    {
        $channel = $catalog->channel();
        $shopProduct = $catalog->product($channel, $slug);
        $availability = $catalog->availability($channel, $shopProduct);

        return response()->view('shop.catalog.show', compact('channel', 'shopProduct', 'availability'))
            ->header('Cache-Control', 'no-store');
    }
}
