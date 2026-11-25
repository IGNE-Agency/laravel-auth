<?php

declare(strict_types=1);

use Igne\Auth\Http\Controllers\AuthenticatableController;
use Igne\Auth\Http\Controllers\NewPasswordController;
use Igne\Auth\Http\Controllers\PasswordController;
use Igne\Auth\Http\Controllers\SessionController;
use Igne\Auth\Http\Middleware\ConcealPasswordResetOutcome;
use Igne\Auth\Http\Middleware\EnsurePasswordAuthenticatable;
use Igne\Auth\LoginCodes\Http\Controllers\ConfirmationCodeController;
use Igne\Auth\LoginCodes\Http\Controllers\LoginCodeController;
use Igne\Auth\Sessions\Http\Controllers\ActiveSessionController;
use Igne\Auth\Sessions\Http\Controllers\TrustedDeviceController;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\TwoFactor\Http\Controllers\TwoFactorChallengeController;
use Igne\Auth\TwoFactor\Http\Middleware\PrepareTwoFactorStepUp;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/*
|--------------------------------------------------------------------------
| Authentication routes
|--------------------------------------------------------------------------
|
| The provider loads this file with the configured prefix and middleware,
| plus AcceptJson. A route points at Fortify's own controller where the
| package changes nothing about its request and its answer. Where the package
| adds to them (login, the two-factor challenge, logout), the route has a
| controller of the package, which hands the request over to Fortify's.
|
*/

Route::post('auth/login', [SessionController::class, 'store'])
    ->middleware(StartHeaderSession::class)
    ->name('authentication.login');

Route::post('auth/logout', [SessionController::class, 'destroy'])
    ->middleware([StartHeaderSession::class, 'auth:sanctum'])
    ->name('authentication.logout');

Route::post('auth/login-code', [LoginCodeController::class, 'store'])
    ->middleware('throttle:authentication-login-code')
    ->name('authentication.login-code');

if (Features::enabled(Features::twoFactorAuthentication())) {
    Route::post('auth/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware([StartHeaderSession::class, PrepareTwoFactorStepUp::class, 'throttle:authentication-two-factor'])
        ->name('authentication.two-factor.challenge');
}

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

if (Features::enabled(Features::twoFactorAuthentication())) {
    Route::middleware(array_filter([
        StartHeaderSession::class,
        'auth:sanctum',
        Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword') ? 'password.confirm' : null,
    ]))->group(static function (): void {
        Route::post('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->name('authentication.two-factor.enable');

        Route::delete('user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('authentication.two-factor.disable');

        Route::post('user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->name('authentication.two-factor.confirm');

        Route::get('user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
            ->name('authentication.two-factor.qr-code');

        Route::get('user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])
            ->name('authentication.two-factor.secret-key');

        Route::get('user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])
            ->name('authentication.two-factor.recovery-codes');

        Route::post('user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
            ->name('authentication.two-factor.regenerate-recovery-codes');
    });

    Route::get('user/devices', [TrustedDeviceController::class, 'index'])
        ->middleware('auth:sanctum')
        ->name('authentication.devices.index');

    Route::delete('user/devices/{trustedDevice}', [TrustedDeviceController::class, 'destroy'])
        ->middleware([StartHeaderSession::class, 'auth:sanctum', 'password.confirm'])
        ->name('authentication.devices.destroy');
}

Route::get('user/sessions', [ActiveSessionController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('authentication.sessions.index');

Route::middleware([StartHeaderSession::class, 'auth:sanctum', 'password.confirm'])->group(static function (): void {
    Route::delete('user/sessions/{session}', [ActiveSessionController::class, 'destroy'])
        ->name('authentication.sessions.destroy');

    Route::delete('user/sessions', [ActiveSessionController::class, 'destroyOthers'])
        ->name('authentication.sessions.destroy-others');
});
