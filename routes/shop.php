<?php

use App\Http\Controllers\Shop\CatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('shop')->name('shop.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');
    Route::get('/products/{slug}', [CatalogController::class, 'show'])->name('products.show');
});
