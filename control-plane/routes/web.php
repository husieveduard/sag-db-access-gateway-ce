<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ResourceCredentialController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\IssueTemporaryCredentialController;
use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\AlertHealthController;
use App\Http\Controllers\Admin\AlertChannelSettingsController;
use App\Http\Controllers\Admin\AdSettingsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'))
    ->name('home');

Route::post('/locale/{locale}', function (
    Request $request,
    string $locale,
) {
    abort_unless(in_array($locale, ['uk', 'en'], true), 404);

    $request->session()->put('locale', $locale);

    return back();
})->middleware('throttle:30,1')
    ->name('locale.update');

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
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:90,1')->name('notifications.feed');
        Route::post('/notifications/read', [NotificationController::class, 'read'])->middleware('throttle:60,1')->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:20,1')->name('notifications.read-all');
        Route::get('/settings', SettingsController::class)->name('settings.index');
        Route::post('/settings/timezone', [SettingsController::class, 'updateTimezone'])
            ->middleware('throttle:10,1')->name('settings.timezone');
        Route::get('/ad-settings', [AdSettingsController::class, 'index'])->name('ad-settings.index');
        Route::post('/ad-settings', [AdSettingsController::class, 'update'])->middleware('throttle:5,1')->name('ad-settings.update');
        Route::post('/ad-settings/test', [AdSettingsController::class, 'test'])->middleware('throttle:3,1')->name('ad-settings.test');
        Route::get('/administrators', [AdministratorController::class, 'index'])->name('administrators.index');
        Route::get('/administrators/create', [AdministratorController::class, 'create'])->name('administrators.create');
        Route::post('/administrators', [AdministratorController::class, 'store'])
            ->middleware('throttle:5,1')->name('administrators.store');
        Route::post('/administrators/{user}/role', [AdministratorController::class, 'updateRole'])
            ->middleware('throttle:10,1')->name('administrators.role');
        Route::post('/administrators/{user}/status', [AdministratorController::class, 'updateStatus'])
            ->middleware('throttle:10,1')->name('administrators.status');
        Route::get('/audit', AuditController::class)->name('audit.index');
        Route::get('/alert-channels', [AlertChannelSettingsController::class, 'index'])->name('alert-channels.index');
        Route::post('/alert-channels/{channel}', [AlertChannelSettingsController::class, 'update'])
            ->middleware('throttle:5,1')->name('alert-channels.update');
        Route::get('/alert-health', [AlertHealthController::class, 'index'])->name('alert-health.index');
        Route::post('/alert-health/test', [AlertHealthController::class, 'test'])->middleware('throttle:3,1')->name('alert-health.test');
        Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/{alert}/status', [AlertController::class, 'updateStatus'])->name('alerts.status');
        Route::get('/alert-rules', [AlertController::class, 'rules'])->name('alert-rules.index');
        Route::post('/alert-rules/{rule}', [AlertController::class, 'updateRule'])->name('alert-rules.update');

        Route::get('/dashboard', DashboardController::class)
            ->name('dashboard');

        Route::get('/resources/create', [ResourceController::class, 'create'])
            ->name('resources.create');

        Route::post('/resources', [ResourceController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('resources.store');

        Route::post('/resources/{resource}/credentials', [ResourceCredentialController::class, 'update'])
            ->middleware('throttle:5,1')->name('resources.credentials.update');

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

        Route::post('/sessions/{session}/temporary-credentials', IssueTemporaryCredentialController::class)
            ->middleware('throttle:3,1')->name('sessions.temporary-credentials');

        Route::get('/sessions/{session}', [SessionController::class, 'show'])
            ->name('sessions.show');

        Route::post('/sessions/{session}/start', [OperationController::class, 'start'])
            ->name('sessions.start');

        Route::post(
            '/sessions/{session}/terminate',
            [OperationController::class, 'terminate']
        )->name('sessions.terminate');
    });
