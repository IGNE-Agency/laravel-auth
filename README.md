# igne-agency/laravel-auth

Headless authentication for Laravel apps, on top of [Laravel Fortify](https://github.com/laravel/fortify) and [Sanctum](https://github.com/laravel/sanctum). The package registers JSON routes that run Fortify's own controllers, for two kinds of clients:

- **Session mode:** an SPA whose requests run through Sanctum's stateful stack logs in to its cookie session.
- **Token mode:** every other client gets an access token from the same login route, and sends it as a bearer token. A header session (keyed by the token, or by the `X-Auth-Challenge` header before login) lets Fortify's session-based flows, such as password confirmation, run unchanged.

The package ships no views. Screens belong to the frontends.

## Why

Fortify and Sanctum give the building blocks, not a finished login API. Without this package, each project wires them up for its own clients and each misses something different: a throttle, a route that skips the two-factor check, a password reset or login code request that reveals whether an account exists, or no record of who logged in where. This package is that API once, tested, and the same in every project: a browser SPA and a mobile app log in on the same routes, two-factor authentication is enforced on every route of the app, users manage their own devices and sessions, and every security event is written to an audit log.

## Features

| Feature | Default | Turn it on or off |
|---|---|---|
| JSON routes for login, logout, `GET user`, password reset and password change | On | `routes.enabled` for all routes; the password routes follow Fortify's `resetPasswords` and `updatePasswords` features. |
| Password login | On for a model that implements `PasswordAuthenticatable` | Override `canAuthenticateWithPassword()` to narrow it. A user without a password can't use it. |
| Login codes: an emailed one-time code instead of a password | Off | `LoginCodeAuthenticatable` on the model. Override `canAuthenticateWithLoginCode()` to narrow it, e.g. to users without a password. |
| Two-factor authentication (TOTP), asked again per device | Off | Fortify's `twoFactorAuthentication` feature and `TwoFactorAuthenticatable` on the model. |
| Required two-factor authentication: users must set it up before they can use the app | On, once two-factor authentication is on | `two_factor.required`, or override `requiresTwoFactorAuthentication()` per user. |
| Trusted devices skip the challenge | For the session or access token; 30 days with "remember device" | `two_factor.device_trust` |
| Identity confirmation for sensitive actions (`password.confirm`), with the strongest factor the user has | On | Fortify's `password.confirm` middleware on the routes that need it. |
| Devices and sessions: users list and revoke their trusted devices, cookie sessions and access tokens | On | Devices need two-factor authentication; cookie sessions are only listed with the `database` session driver. |
| Audit log of security events | On | `audit.enabled` |
| Daily pruning of expired tokens, login codes, trusted devices and old audit entries | On | `schedule` |
| Nova's login, challenge and security screens | When Nova 5 is installed | Automatic; see [Nova](docs/nova.md). |
| OpenAPI document of every package route | When Scramble 0.13 is installed | Automatic; see [OpenAPI](docs/openapi.md). |

## Requirements

- PHP 8.4
- Laravel 13
- laravel/fortify 1.42 or later, laravel/sanctum 4.4 or later

## Install

```bash
composer require igne-agency/laravel-auth
php artisan vendor:publish --tag=authentication-config
php artisan vendor:publish --tag=authentication-migrations
php artisan vendor:publish --tag=sanctum-migrations
php artisan vendor:publish --tag=fortify-config
php artisan migrate
```

Then:

- set `views` to `false` in the published `config/fortify.php`;
- call `$middleware->statefulApi()` in `bootstrap/app.php`;
- let the authenticatable model (usually `User`) implement `Igne\Auth\Authentication\Contracts\PasswordAuthenticatable` with the `AuthenticatesWithPassword` trait, and use Sanctum's `HasApiTokens`;
- for login codes, let it also implement `Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable` with the `AuthenticatesWithLoginCode` trait;
- for two-factor authentication, keep Fortify's `twoFactorAuthentication` feature enabled in `config/fortify.php`, publish Fortify's migrations for the two-factor columns on `users` (`php artisan vendor:publish --tag=fortify-migrations`, then `php artisan migrate`; the tag also publishes Fortify's `passkeys` table) unless the table has them, and let the model implement `Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable` with the package's `InteractsWithTwoFactorAuthentication` trait, which includes Fortify's `TwoFactorAuthenticatable` trait.

Every login runs through the package's credential check on `Fortify::authenticateUsing`. Don't add login routes of your own next to it: they would skip the throttle, the two-factor challenge and the audit log.

## Documentation

Setting up the package in an app:

- [Configuration](docs/configuration.md): every key of `config/authentication.php`, the settings projects change most, and swapping the mails.
- [Session and token clients](docs/clients.md): the backend setup for an SPA on a cookie session, the token flow for every other client, and Passport.
- [Login codes](docs/login-codes.md): logging in and confirming identity with an emailed one-time code.
- [Two-factor authentication](docs/two-factor-authentication.md): the per-device challenge, its enforcement on the app's routes, and resetting a user's two-factor authentication.
- [Devices and sessions](docs/devices-and-sessions.md): the endpoints users list and revoke their devices, sessions and access tokens with.
- [Audit log](docs/audit-log.md): the security events the package records, how to read them, and sending them elsewhere.
- [Routes](docs/routes.md): every package route, and keeping the app's own routes behind the two-factor enforcement.
- [Nova](docs/nova.md): letting Nova's login, challenge and security screens handle authentication.
- [OpenAPI](docs/openapi.md): the package's routes in the app's Scramble document.
- [AI guidelines](docs/ai-guidelines.md): the Laravel Boost guideline and skill the package ships.

Also:

- [Concepts](docs/concepts.md) explains the words IGNE projects use for authentication and authorization (identity, provider, space, role, permission, scope, audit log), what this package builds of them, the naming to use and avoid, and the authorization layers a request passes.
- The [frontend guide](docs/frontend.md) is for frontend developers: how to read the endpoints and schemas, set up a session-mode SPA or a token client, every authentication flow, and the errors to handle.
- [Development](docs/development.md) covers working on the package itself: the checks, the tests and CI.
