<?php

use App\Http\Controllers\InternalDbGatewayController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'edition' => config('sag.edition'),
    ]);
});

Route::prefix('internal/db')->group(function () {
    Route::post('/session-status', [InternalDbGatewayController::class, 'sessionStatus']);
    Route::post('/connection-started', [InternalDbGatewayController::class, 'connectionStarted']);
    Route::post('/connection-denied', [InternalDbGatewayController::class, 'connectionDenied']);
    Route::post('/connection-ended', [InternalDbGatewayController::class, 'connectionEnded']);
    Route::post('/query-event', [InternalDbGatewayController::class, 'queryEvent']);
});
