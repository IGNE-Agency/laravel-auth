<?php

declare(strict_types=1);

use Igne\Auth\Http\Controllers\AuthenticatableController;
use Igne\Auth\Http\Controllers\NewPasswordController;
use Igne\Auth\Http\Controllers\PasswordController;
use Igne\Auth\Http\Middleware\ConcealPasswordResetOutcome;
use Igne\Auth\Http\Middleware\EnsurePasswordAuthenticatable;
use Igne\Auth\LoginCodes\Http\Controllers\ConfirmationCodeController;
use Igne\Auth\LoginCodes\Http\Controllers\LoginCodeController;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

/*
|--------------------------------------------------------------------------
| Authentication routes
|--------------------------------------------------------------------------
|
| The provider loads this file with the configured prefix and middleware,
| plus AcceptJson. The routes point at Fortify's own controllers, or at a thin
| subclass that validates the input with a FormRequest first.
|
*/

Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(StartHeaderSession::class)
    ->name('authentication.login');

Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware([StartHeaderSession::class, 'auth:sanctum'])
    ->name('authentication.logout');

Route::post('auth/login-code', [LoginCodeController::class, 'store'])
    ->middleware('throttle:authentication-login-code')
    ->name('authentication.login-code');

if (Features::enabled(Features::resetPasswords())) {
    Route::post('auth/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware(['throttle:authentication-password-reset', ConcealPasswordResetOutcome::class])
        ->name('authentication.password.email');

    Route::post('auth/reset-password', NewPasswordController::class)
        ->name('authentication.password.update');
}

Route::get('user', AuthenticatableController::class)
    ->middleware('auth:sanctum')
    ->name('authentication.user');

if (Features::enabled(Features::updatePasswords())) {
    Route::put('user/password', PasswordController::class)
        ->middleware(['auth:sanctum', EnsurePasswordAuthenticatable::class])
        ->name('authentication.user-password.update');
}

Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware([StartHeaderSession::class, 'auth:sanctum'])
    ->name('authentication.password.confirm.store');

Route::get('user/confirmed-password-status', [ConfirmedPasswordStatusController::class, 'show'])
    ->middleware([StartHeaderSession::class, 'auth:sanctum'])
    ->name('authentication.password.confirmation');

Route::post('user/confirm-password/code', [ConfirmationCodeController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:authentication-confirmation-code'])
    ->name('authentication.password.confirm.code');
