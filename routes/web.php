<?php

use DriveSnap\Http\DashboardController;
use DriveSnap\Http\GoogleConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('drivesnap.middleware'))->prefix(config('drivesnap.prefix'))->name('drivesnap.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::get('/status', [DashboardController::class, 'status'])->name('status');
    Route::post('/backups', [DashboardController::class, 'store'])->middleware('throttle:3,1,drivesnap-create:')->name('store');
    Route::post('/backups/{backup}/retry', [DashboardController::class, 'retry'])->middleware('throttle:3,1,drivesnap-create:')->name('retry');
    Route::get('/backups/{backup}/download', [DashboardController::class, 'download'])->middleware('throttle:10,1,drivesnap-download:')->name('download');
    Route::delete('/backups/{backup}', [DashboardController::class, 'destroy'])->name('destroy');
    Route::put('/settings', [DashboardController::class, 'settings'])->name('settings');
    Route::get('/google/connect', [GoogleConnectionController::class, 'connect'])->middleware('throttle:6,1,drivesnap-google:')->name('connect');
    Route::get('/google/callback', [GoogleConnectionController::class, 'callback'])->name('callback');
    Route::delete('/google', [GoogleConnectionController::class, 'disconnect'])->name('disconnect');
});
