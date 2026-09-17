<?php

use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\PayFastController;
use App\Http\Controllers\Shop\AdminOrderController;
use App\Http\Controllers\Shop\AdminCatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('shop')->name('shop.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');
    Route::get('/products/{slug}', [CatalogController::class, 'show'])->name('products.show');
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    Route::post('/cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('cart.items.update');
    Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('cart.items.destroy');
    Route::get('/checkout', [CheckoutController::class, 'create'])->name('checkout');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/orders/{uuid}', [CheckoutController::class, 'show'])->middleware('signed')->name('orders.show');
    Route::post('/orders/{uuid}/pay', [PayFastController::class, 'start'])->middleware('signed')->name('payfast.start');
    Route::get('/payfast/return/{payment}', [PayFastController::class, 'returned'])->middleware('signed')->name('payfast.return');
    Route::get('/payfast/cancel/{payment}', [PayFastController::class, 'cancelled'])->middleware('signed')->name('payfast.cancel');
    Route::post('/payfast/notify', [PayFastController::class, 'notify'])->name('payfast.notify');
});

Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu', 'CheckUserLogin'])
    ->prefix('shop-admin')->name('shop.admin.')->group(function () {
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{uuid}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{uuid}/ready', [AdminOrderController::class, 'ready'])->name('orders.ready');
        Route::post('/orders/{uuid}/collected', [AdminOrderController::class, 'collected'])->name('orders.collected');
        Route::post('/orders/{uuid}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
        Route::get('/catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalog/channels', [AdminCatalogController::class, 'storeChannel'])->name('catalog.channels.store');
        Route::get('/catalog/{channel}/products', [AdminCatalogController::class, 'products'])->name('catalog.products');
        Route::get('/catalog/{channel}/products/{product}', [AdminCatalogController::class, 'edit'])->name('catalog.products.edit');
        Route::put('/catalog/{channel}/products/{product}', [AdminCatalogController::class, 'update'])->name('catalog.products.update');
    });
