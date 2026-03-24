<?php

use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
    Route::delete('/videos/{video}', [VideoController::class, 'destroy']);
    Route::get('/videos/{video}/jobs/{job}/download', [VideoController::class, 'download']);
});
