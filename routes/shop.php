<?php

use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\PayFastController;
use App\Http\Controllers\Shop\AdminOrderController;
use App\Http\Controllers\Shop\AdminCatalogController;
use App\Http\Controllers\Shop\CustomerAuthController;
use App\Http\Controllers\Shop\CustomerDashboardController;
use App\Http\Controllers\Shop\CustomerAccountPaymentController;
use App\Http\Controllers\Shop\CustomerLinkAdminController;
use App\Http\Controllers\Shop\PaymentReviewController;
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

    Route::prefix('account')->name('account.')->group(function () {
        Route::middleware('shop.customer:guest')->group(function () {
            Route::get('/login', [CustomerAuthController::class, 'loginForm'])->name('login');
            Route::post('/login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
            Route::get('/register', [CustomerAuthController::class, 'registerForm'])->name('register');
            Route::post('/register', [CustomerAuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
            Route::get('/forgot-password', [CustomerAuthController::class, 'forgotForm'])->name('password.request');
            Route::post('/forgot-password', [CustomerAuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
            Route::get('/reset-password/{token}', [CustomerAuthController::class, 'resetForm'])->name('password.reset');
            Route::post('/reset-password', [CustomerAuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
        });
        Route::post('/payfast/notify', [CustomerAccountPaymentController::class, 'notify'])->name('payfast.notify');
        Route::middleware('shop.customer:customer')->group(function () {
            Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('logout');
            Route::get('/verify-email', [CustomerAuthController::class, 'verificationNotice'])->name('verification.notice');
            Route::get('/verify-email/{id}/{hash}', [CustomerAuthController::class, 'verify'])->middleware('signed')->name('verify');
            Route::post('/verification-notification', [CustomerAuthController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');
        });
        Route::middleware('shop.customer:verified')->group(function () {
            Route::get('/', [CustomerDashboardController::class, 'index'])->name('dashboard');
            Route::get('/orders/{uuid}', [CustomerDashboardController::class, 'showOrder'])->name('orders.show');
            Route::post('/invoices/{transaction}/pay', [CustomerAccountPaymentController::class, 'start'])->name('invoices.pay');
            Route::get('/payfast/return/{payment}', [CustomerAccountPaymentController::class, 'returned'])->middleware('signed')->name('payfast.return');
            Route::get('/payfast/cancel/{payment}', [CustomerAccountPaymentController::class, 'cancelled'])->middleware('signed')->name('payfast.cancel');
        });
    });
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
        Route::get('/catalog/channels/{channel}/edit', [AdminCatalogController::class, 'editChannel'])->name('catalog.channels.edit');
        Route::patch('/catalog/channels/{channel}', [AdminCatalogController::class, 'updateChannel'])->name('catalog.channels.update');
        Route::get('/catalog/{channel}/products', [AdminCatalogController::class, 'products'])->name('catalog.products');
        Route::get('/catalog/{channel}/products/{product}', [AdminCatalogController::class, 'edit'])->name('catalog.products.edit');
        Route::put('/catalog/{channel}/products/{product}', [AdminCatalogController::class, 'update'])->name('catalog.products.update');
        Route::get('/customer-links', [CustomerLinkAdminController::class, 'index'])->name('customer-links.index');
        Route::post('/customer-links/{link}/verify', [CustomerLinkAdminController::class, 'verify'])->name('customer-links.verify');
        Route::post('/customer-links/{link}/revoke', [CustomerLinkAdminController::class, 'revoke'])->name('customer-links.revoke');
        Route::get('/payment-reviews', [PaymentReviewController::class, 'index'])->name('payment-reviews.index');
        Route::post('/payment-reviews/orders/{payment}/retry', [PaymentReviewController::class, 'retryOrder'])->name('payment-reviews.orders.retry');
    });
