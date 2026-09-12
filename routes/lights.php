<?php

use App\Http\Controllers\LightsController;
use App\Http\Middleware\LightsAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('lights')->name('lights.')->middleware(LightsAccess::class.':public')->group(function () {
    Route::post('payfast/notify', [LightsController::class, 'payfastNotify'])->middleware('throttle:120,1')->name('payfast.notify');
    Route::get('service-worker.js', [LightsController::class, 'serviceWorker'])->name('service-worker');
    Route::get('login', [LightsController::class, 'login'])->name('login');
    Route::post('login', [LightsController::class, 'authenticate'])->middleware('throttle:10,1');
    Route::post('register', [LightsController::class, 'register'])->middleware('throttle:5,1')->name('register');
    Route::get('terms', [LightsController::class, 'terms'])->name('terms');
    Route::get('privacy', [LightsController::class, 'privacy'])->name('privacy');
    Route::post('forgot-password', [LightsController::class, 'forgotPassword'])->middleware('throttle:3,1')->name('password.email');
    Route::get('reset-password/{token}', [LightsController::class, 'resetPasswordForm'])->where('token', '[a-f0-9]{64}')->name('password.reset');
    Route::post('reset-password', [LightsController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::get('verify/{token}', [LightsController::class, 'verifyEmail'])->where('token', '[a-f0-9]{64}')->name('verify');
    Route::middleware(LightsAccess::class.':member')->group(function () {
        Route::get('/', [LightsController::class, 'index'])->name('home');
        Route::post('logout', [LightsController::class, 'logout'])->name('logout');
        Route::get('state', [LightsController::class, 'state'])->name('state');
        Route::post('courts/{court}/start', [LightsController::class, 'start'])->whereNumber('court')->name('start');
        Route::post('sessions/{session}/stop', [LightsController::class, 'stop'])->whereUuid('session')->name('stop');
        Route::post('topups', [LightsController::class, 'topup'])->name('topup');
        Route::post('verification/send', [LightsController::class, 'sendVerification'])->middleware('throttle:3,1')->name('verification.send');
        Route::get('topups/{topup}', [LightsController::class, 'checkout'])->whereUuid('topup')->name('checkout');
        Route::post('topups/{topup}/simulate', [LightsController::class, 'simulate'])->whereUuid('topup')->name('simulate');
        Route::get('topups/{topup}/payfast/return', [LightsController::class, 'payfastReturn'])->whereUuid('topup')->name('payfast.return');
        Route::get('topups/{topup}/payfast/cancel', [LightsController::class, 'payfastCancel'])->whereUuid('topup')->name('payfast.cancel');
        Route::middleware(LightsAccess::class.':admin')->group(function () {
            Route::get('admin', [LightsController::class, 'admin'])->name('admin');
            Route::get('admin/health', [LightsController::class, 'health'])->name('admin.health');
            Route::post('admin/members/{member}/status', [LightsController::class, 'memberStatus'])->whereNumber('member')->name('admin.members.status');
            Route::post('admin/members/{member}/adjustment', [LightsController::class, 'memberAdjustment'])->whereNumber('member')->middleware('throttle:10,1')->name('admin.members.adjustment');
            Route::get('admin/hardware-state', [LightsController::class, 'hardwareState'])->name('admin.hardware-state');
            Route::get('admin/control', [LightsController::class, 'control'])->name('admin.control');
            Route::post('admin/control/manual-on', [LightsController::class, 'controlManualOn'])->middleware('throttle:12,1')->name('admin.control.manual-on');
            Route::post('admin/control/emergency-off', [LightsController::class, 'controlEmergencyOff'])->middleware('throttle:6,1')->name('admin.control.emergency-off');
            Route::post('admin/control/arm-customer', [LightsController::class, 'armCustomerControl'])->middleware('throttle:6,1')->name('admin.control.arm-customer');
            Route::post('admin/control/{session}/stop', [LightsController::class, 'controlStop'])->whereUuid('session')->name('admin.control.stop');
            Route::post('admin/control/{session}/review', [LightsController::class, 'controlReview'])->whereUuid('session')->name('admin.control.review');
            Route::get('admin/shelly', [LightsController::class, 'shelly'])->name('admin.shelly');
            Route::post('admin/shelly', [LightsController::class, 'saveShelly'])->middleware('throttle:6,1')->name('admin.shelly.save');
            Route::post('admin/shelly/check', [LightsController::class, 'checkShelly'])->middleware('throttle:6,1')->name('admin.shelly.check');
            Route::post('admin/courts', [LightsController::class, 'saveCourt'])->name('admin.courts');
            Route::post('admin/payfast', [LightsController::class, 'savePayFast'])->middleware('throttle:6,1')->name('admin.payfast');
            Route::post('admin/sessions/{session}/stop', [LightsController::class, 'emergencyStop'])->whereUuid('session')->name('admin.stop');
        });
    });
});
