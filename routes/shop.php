<?php

use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\PayFastController;
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
