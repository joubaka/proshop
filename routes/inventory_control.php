<?php
use App\Http\Controllers\InventoryControlController;
use Illuminate\Support\Facades\Route;
Route::middleware(['setData','auth','SetSessionData','language','timezone','AdminSidebarMenu','CheckUserLogin'])->prefix('inventory-control')->name('inventory-control.')->group(function(){
    Route::get('/',[InventoryControlController::class,'index'])->name('index');
    Route::post('/policies',[InventoryControlController::class,'savePolicies'])->name('policies');
    Route::post('/replenishment/preview',[InventoryControlController::class,'previewReplenishment'])->name('replenishment.preview');
    Route::get('/replenishment/{uuid}',[InventoryControlController::class,'showReplenishment'])->name('replenishment.show');
    Route::post('/replenishment/{uuid}/confirm',[InventoryControlController::class,'confirmReplenishment'])->name('replenishment.confirm');
    Route::post('/replenishment/{uuid}/discard',[InventoryControlController::class,'discardReplenishment'])->name('replenishment.discard');
    Route::post('/counts',[InventoryControlController::class,'createCount'])->name('counts.create');
    Route::get('/counts/{uuid}',[InventoryControlController::class,'showCount'])->name('counts.show');
    Route::put('/counts/{uuid}',[InventoryControlController::class,'updateCount'])->name('counts.update');
    Route::post('/counts/{uuid}/scan',[InventoryControlController::class,'scan'])->name('counts.scan');
    Route::post('/counts/{uuid}/post',[InventoryControlController::class,'postCount'])->name('counts.post');
    Route::post('/counts/{uuid}/discard',[InventoryControlController::class,'discardCount'])->name('counts.discard');
});
