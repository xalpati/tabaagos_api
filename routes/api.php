<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\PaymentAttemptController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pey', WebhookController::class)->middleware('throttle:120,1');

// Rutas de distribución de la APK
Route::get('/downloads/android', [DownloadController::class, 'meta']);
Route::post('/download-registrations', [DownloadController::class, 'register'])->middleware('throttle:30,1');
Route::get('/downloads/apk/{token}', [DownloadController::class, 'download']);

Route::prefix('v1')->group(function () {
    Route::get('/downloads/meta', [DownloadController::class, 'meta']);
    Route::post('/download-registrations', [DownloadController::class, 'register'])->middleware('throttle:30,1');
    Route::get('/downloads/apk/{token}', [DownloadController::class, 'download']);

    Route::post('/auth/google', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', 'device', 'throttle:mobile'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/devices/current/push-token', [DeviceController::class, 'update']);
        Route::delete('/devices/current/push-token', [DeviceController::class, 'destroy']);
        Route::post('/bank-account', [BankAccountController::class, 'store'])->middleware('throttle:5,1');
        Route::get('/bank-account', [BankAccountController::class, 'show']);
        Route::post('/bank-account/refresh', [BankAccountController::class, 'refresh'])->middleware('throttle:6,1');
        Route::post('/payment-attempts', [PaymentAttemptController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/payment-attempts/{id}/abandon', [PaymentAttemptController::class, 'abandon']);
        Route::post('/events/ack', [EventController::class, 'ack']);
        Route::post('/events/resume', [EventController::class, 'resume'])->middleware('throttle:6,1');
    });
});
