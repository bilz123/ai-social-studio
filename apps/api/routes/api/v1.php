<?php

use App\Enums\SocialPlatform;
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
use App\Http\Controllers\Api\V1\Social\SocialAccountController;
use App\Http\Controllers\Api\V1\Social\SocialConnectionController;
use App\Http\Controllers\Api\V1\Social\SocialPlatformController;
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

/*
| Connected social accounts (Facebook Pages, Instagram, YouTube). OAuth: the SPA calls
| POST /{platform}/connect, sends the user to the returned provider URL, receives the provider
| redirect on /connections/callback/{platform} and forwards code + state to POST /{platform}/callback.
*/
Route::middleware(['auth:sanctum', 'can:social-accounts.manage'])->prefix('social')->name('social.')->group(function (): void {
    Route::get('/platforms', [SocialPlatformController::class, 'index'])->name('platforms.index');

    Route::get('/accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::patch('/accounts/{account}', [SocialAccountController::class, 'update'])
        ->middleware('throttle:social-manage')->name('accounts.update');
    Route::post('/accounts/{account}/check', [SocialAccountController::class, 'check'])
        ->middleware('throttle:social-manage')->name('accounts.check');
    Route::delete('/accounts/{account}', [SocialAccountController::class, 'destroy'])
        ->middleware('throttle:social-manage')->name('accounts.destroy');

    Route::post('/{platform}/connect', [SocialConnectionController::class, 'connect'])
        ->whereIn('platform', SocialPlatform::slugs())
        ->middleware('throttle:social-connect')->name('connect');
    Route::post('/{platform}/callback', [SocialConnectionController::class, 'callback'])
        ->whereIn('platform', SocialPlatform::slugs())
        ->middleware('throttle:social-connect')->name('callback');
});
