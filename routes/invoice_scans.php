<?php

use App\Http\Controllers\InvoiceScanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu', 'CheckUserLogin'])
    ->prefix('invoice-scans')
    ->name('invoice-scans.')
    ->group(function () {
        Route::get('/', [InvoiceScanController::class, 'index'])->name('index');
        Route::post('/', [InvoiceScanController::class, 'store'])->name('store');
        Route::get('/{uuid}', [InvoiceScanController::class, 'show'])->name('show');
        Route::put('/{uuid}', [InvoiceScanController::class, 'update'])->name('update');
        Route::post('/{uuid}/process', [InvoiceScanController::class, 'process'])->name('process');
        Route::post('/{uuid}/post', [InvoiceScanController::class, 'post'])->name('post');
        Route::get('/{uuid}/documents/{document}', [InvoiceScanController::class, 'document'])->name('document');
    });
