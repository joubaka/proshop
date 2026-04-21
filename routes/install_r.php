<?php

/*
|--------------------------------------------------------------------------
| Installation Web Routes
|--------------------------------------------------------------------------
|
| Routes related to installation of the software
|
*/

Route::get('/install-start', 'App\Http\Controllers\Install\InstallController@index')->name('install.index');
Route::get('/install/check-server', 'App\Http\Controllers\Install\InstallController@checkServer')->name('install.checkServer');
Route::get('/install/details', 'App\Http\Controllers\Install\InstallController@details')->name('install.details');
Route::post('/install/post-details', 'App\Http\Controllers\Install\InstallController@postDetails')->name('install.postDetails');
Route::post('/install/install-alternate', 'App\Http\Controllers\Install\InstallController@installAlternate')->name('install.installAlternate');
Route::get('/install/success', 'App\Http\Controllers\Install\InstallController@success')->name('install.success');

Route::get('/install/update', 'App\Http\Controllers\Install\InstallController@updateConfirmation')->name('install.updateConfirmation');
Route::post('/install/update', 'App\Http\Controllers\Install\InstallController@update')->name('install.update');