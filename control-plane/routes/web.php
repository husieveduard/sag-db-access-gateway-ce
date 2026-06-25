<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'))
    ->name('home');

Route::get('/login', [LoginController::class, 'create'])
    ->name('login');

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('login.store');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/mfa/setup', [MfaController::class, 'setup'])
    ->name('mfa.setup');

Route::post('/mfa/setup', [MfaController::class, 'confirmSetup'])
    ->middleware('throttle:10,1')
    ->name('mfa.setup.store');

Route::get('/mfa/challenge', [MfaController::class, 'challenge'])
    ->name('mfa.challenge');

Route::post('/mfa/challenge', [MfaController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('mfa.challenge.verify');

Route::middleware(['auth', 'ce.admin', 'ce.mfa'])
    ->group(function (): void {
        Route::get('/mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])
            ->name('mfa.recovery.show');

        Route::post(
            '/mfa/recovery-codes/acknowledge',
            [MfaController::class, 'acknowledgeRecoveryCodes']
        )->name('mfa.recovery.acknowledge');
    });

Route::middleware(['auth', 'ce.admin', 'ce.mfa'])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class)
            ->name('dashboard');

        Route::get('/resources/create', [ResourceController::class, 'create'])
            ->name('resources.create');

        Route::post('/resources', [ResourceController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('resources.store');

        Route::get('/resources/{resource}/edit', [ResourceController::class, 'edit'])
            ->name('resources.edit');

        Route::put('/resources/{resource}', [ResourceController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('resources.update');

        Route::post('/resources/{resource}/deactivate', [ResourceController::class, 'deactivate'])
            ->middleware('throttle:10,1')
            ->name('resources.deactivate');

        Route::post('/resources/{resource}/activate', [ResourceController::class, 'activate'])
            ->middleware('throttle:10,1')
            ->name('resources.activate');

        Route::get('/resources', [ResourceController::class, 'index'])
            ->name('resources.index');

        Route::get('/resources/{resource}', [ResourceController::class, 'show'])
            ->name('resources.show');

        Route::get('/sessions/create', [SessionController::class, 'create'])
            ->name('sessions.create');

        Route::post('/sessions', [SessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('sessions.store');

        Route::get('/sessions', [SessionController::class, 'index'])
            ->name('sessions.index');

        Route::get('/sessions/{session}', [SessionController::class, 'show'])
            ->name('sessions.show');

        Route::post('/sessions/{session}/start', [OperationController::class, 'start'])
            ->name('sessions.start');

        Route::post(
            '/sessions/{session}/terminate',
            [OperationController::class, 'terminate']
        )->name('sessions.terminate');
    });
