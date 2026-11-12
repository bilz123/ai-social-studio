<?php

use App\Http\Controllers\Api\V1\Account\AccountController;
use App\Http\Controllers\Api\V1\Account\ActivityController;
use App\Http\Controllers\Api\V1\Account\PasswordController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Admin\UserIndexController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

/*
| Authentication. The SPA first calls GET /api/sanctum/csrf-cookie, then sends the XSRF token
| header on every state-changing request. Sessions are cookie based (Sanctum SPA mode).
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('register');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login');

    Route::post('/forgot-password', PasswordResetLinkController::class)
        ->middleware('throttle:password-email')->name('password.email');
    Route::post('/reset-password', NewPasswordController::class)
        ->middleware('throttle:password-reset')->name('password.store');

    // Opened from the email link (via the SPA); proven by the URL signature, not by a session.
    Route::get('/verify-email/{id}/{hash}', [VerifyEmailController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:verification'])->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        Route::get('/me', MeController::class)->name('me');
        Route::post('/email/verification-notification', [VerifyEmailController::class, 'resend'])
            ->middleware('throttle:verification')->name('verification.send');
    });
});

Route::middleware('auth:sanctum')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:sensitive')->name('profile.update');
    Route::put('/password', [PasswordController::class, 'update'])
        ->middleware('throttle:sensitive')->name('password.update');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::delete('/', [AccountController::class, 'destroy'])
        ->middleware('throttle:sensitive')->name('destroy');
});

// Administration: access is decided by permissions, never by "is this the same user".
Route::middleware(['auth:sanctum', 'can:users.view'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/users', UserIndexController::class)->name('users.index');
});
