<?php

declare(strict_types=1);

use Igne\Auth\Authentication\Notifications\ResetPasswordNotification;
use Igne\Auth\Http\Resources\AuthenticatableResource;
use Igne\Auth\Tokens\SanctumAccessTokenIssuer;

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
    | to run daily.
    |
    */

    'schedule' => true,

];
