<?php

use App\Http\Controllers\VideoController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\ValidateWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::get('/videos/{video}/jobs/{job}/download', [VideoController::class, 'download'])
        ->name('videos.download');
    Route::delete('/videos/{video}', [VideoController::class, 'destroy'])
        ->name('videos.destroy');
});

Route::post('webhooks/transcode', [WebhookController::class, 'handle'])
    ->middleware(ValidateWebhookSignature::class)
    ->name('webhooks.transcode');

require __DIR__.'/settings.php';
