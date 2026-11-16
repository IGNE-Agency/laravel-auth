<?php

declare(strict_types=1);

use Igne\Auth\Authentication\Notifications\ResetPasswordNotification;
use Igne\Auth\Http\Resources\AuthenticatableResource;
use Igne\Auth\LoginCodes\Actions\ConsumeLoginCodeAction;
use Igne\Auth\LoginCodes\Actions\CreateLoginCodeAction;
use Igne\Auth\LoginCodes\Generators\NumericLoginCodeGenerator;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Igne\Auth\Tokens\SanctumAccessTokenIssuer;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Igne\Auth\TwoFactor\Notifications\TwoFactorAuthenticationResetNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package registers its own JSON routes and points them at Fortify's
    | controllers. Every route also gets the AcceptJson middleware, so Fortify
    | always answers with its JSON responses.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticatable resource
    |--------------------------------------------------------------------------
    |
    | The JSON resource that GET /api/user returns. Extend the default
    | resource to add project fields.
    |
    */

    'auth_resource' => AuthenticatableResource::class,

    /*
    |--------------------------------------------------------------------------
    | Login codes
    |--------------------------------------------------------------------------
    |
    | Users that implement LoginCodeAuthenticatable can log in with an emailed
    | one-time code instead of a password: POST /auth/login-code sends it, and
    | the login route takes it in the "password" field. Users without a
    | password confirm their identity the same way, with a code from
    | POST /user/confirm-password/code.
    |
    | Only an HMAC of a code is stored. A code takes "max_attempts_per_code"
    | wrong attempts; "rate_limit_attempts" limits a user's wrong attempts
    | across codes, and a new code does not reset it. "rate_limit_requests"
    | limits how often codes are sent per email address (per user for
    | confirmation codes) and per IP address.
    |
    | The mail links to "link_url" when it is set, with the "{email}" and
    | "{code}" placeholders filled in. That frontend page posts the code to
    | the login route.
    |
    */

    'login_codes' => [
        'default_expires_in_minutes' => 10,
        'only_one_active_login_code_per_user' => true,
        'code_generator' => NumericLoginCodeGenerator::class,
        'code_length' => 6,
        'max_attempts_per_code' => 5,
        'rate_limit_attempts' => [
            'max_attempts_per_user' => 20,
            'time_window_in_seconds' => 3600,
        ],
        'rate_limit_requests' => [
            'max_requests_per_email' => 3,
            'max_requests_per_ip' => 10,
            'time_window_in_seconds' => 60,
        ],
        'link_url' => env('AUTH_LOGIN_CODE_URL'),
        'model' => LoginCode::class,
        'notification' => LoginCodeNotification::class,
        'actions' => [
            'create_login_code' => CreateLoginCodeAction::class,
            'consume_login_code' => ConsumeLoginCodeAction::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Users that implement TwoFactorAuthenticatable get Fortify's TOTP
    | two-factor authentication, asked again per device. With "required", users
    | must set it up before they can use the app ("two_factor_setup_required"),
    | and a logged-in user on a device that did not pass the challenge gets
    | "two_factor_required" on every route in the "web" and "api" groups.
    |
    | "device_trust" sets how long a device stays trusted after the challenge:
    | without "remember_device", "session" (the cookie session or the access
    | token) or a number of days (1-365); with it, "remember_expires_in_days".
    | "extend_on_use" extends the expiry on every use, but never past 365 days
    | after the last challenge. A trusted device sends its device token back
    | as the "cookie" (session mode) or the "header" (token mode).
    |
    | "except" lists route names or URI patterns that skip every check, and
    | "except_setup" those that only skip the setup check.
    |
    */

    'two_factor' => [
        'required' => true,
        'device_trust' => [
            'expires_in_days' => 'session',
            'remember_expires_in_days' => 30,
            'extend_on_use' => false,
            'cookie' => 'two_factor_device',
            'header' => 'X-Two-Factor-Device',
        ],
        'except' => [],
        'except_setup' => [],
        'reset_notification' => TwoFactorAuthenticationResetNotification::class,
        'model' => TrustedDevice::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Access tokens
    |--------------------------------------------------------------------------
    |
    | Bearer-token clients get an access token from the issuer after login.
    | "expires_in_minutes" sets an absolute lifetime (null = no limit) and
    | "idle_expires_in_minutes" rejects a token that was not used for that
    | long (null = no idle limit).
    |
    */

    'tokens' => [
        'issuer' => SanctumAccessTokenIssuer::class,
        'expires_in_minutes' => 60 * 24 * 30,
        'idle_expires_in_minutes' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Header sessions
    |--------------------------------------------------------------------------
    |
    | Bearer-token clients get a server-side session that is identified by a
    | header instead of a cookie: the bearer token after login, or the
    | challenge header before login. Fortify's challenge and password
    | confirmation flows run on it unchanged.
    |
    */

    'header_sessions' => [
        'challenge_header' => 'X-Auth-Challenge',
        'cache_store' => null,
        'challenge_lifetime_minutes' => 10,
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passwords
    |--------------------------------------------------------------------------
    |
    | The default reset notification links to "reset_url", in which the
    | "{token}" and "{email}" placeholders are filled in.
    |
    */

    'passwords' => [
        'reset_url' => env('AUTH_PASSWORD_RESET_URL'),
        'reset_notification' => ResetPasswordNotification::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAPI
    |--------------------------------------------------------------------------
    |
    | The session cookie name in the generated OpenAPI document. Null uses
    | "session.cookie", which usually derives from APP_NAME. Pin it when the
    | document is committed, so every environment generates the same one.
    |
    */

    'openapi' => [
        'session_cookie' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | When enabled, the package schedules "sanctum:prune-expired --hours=24"
    | and "model:prune" for its prunable models (the login code and trusted
    | device models) to run daily.
    |
    */

    'schedule' => true,

];
