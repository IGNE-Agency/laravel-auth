# igne-agency/laravel-auth

Headless authentication for Laravel apps, on top of [Laravel Fortify](https://github.com/laravel/fortify) and [Sanctum](https://github.com/laravel/sanctum). The package registers JSON routes that run Fortify's own controllers, for two kinds of clients:

- **Session mode:** an SPA whose requests run through Sanctum's stateful stack logs in to its cookie session.
- **Token mode:** every other client gets an access token from the same login route, and sends it as a bearer token. A header session (keyed by the token, or by the `X-Auth-Challenge` header before login) lets Fortify's session-based flows, such as password confirmation, run unchanged.

The package ships no views. Screens belong to the frontends.

## Requirements

- PHP 8.4
- Laravel 13
- laravel/fortify 1.42 or later, laravel/sanctum 4.4 or later

## Install

```bash
composer require igne-agency/laravel-auth
php artisan vendor:publish --tag=authentication-config
php artisan vendor:publish --tag=sanctum-migrations
```

Then:

- set `views` to `false` in `config/fortify.php`;
- call `$middleware->statefulApi()` in `bootstrap/app.php`;
- let the authenticatable model (usually `User`) implement `Igne\Auth\Authentication\Contracts\PasswordAuthenticatable` with the `AuthenticatesWithPassword` trait, and use Sanctum's `HasApiTokens`.

## Routes

With the default prefix `api`:

| Method | URI | Name |
|---|---|---|
| POST | `auth/login` | `authentication.login` |
| POST | `auth/logout` | `authentication.logout` |
| POST | `auth/forgot-password` | `authentication.password.email` |
| POST | `auth/reset-password` | `authentication.password.update` |
| GET | `user` | `authentication.user` |
| PUT | `user/password` | `authentication.user-password.update` |
| POST | `user/confirm-password` | `authentication.password.confirm.store` |
| GET | `user/confirmed-password-status` | `authentication.password.confirmation` |

The password routes follow Fortify's `resetPasswords` and `updatePasswords` features. They run thin subclasses of Fortify's controllers that validate the input with `ResetPasswordRequest` and `UpdatePasswordRequest` first; the strength of a new password comes from the app's `Password::defaults()`. When the app uses [Scramble](https://scramble.dedoc.co), the package documents its routes in the app's OpenAPI document.

## Tests

The tests are Pest tests on Testbench. Inside an app, bind the test case in the app's `tests/Pest.php`:

```php
pest()->extend(Igne\Auth\Tests\TestCase::class)->in('../packages/igne-agency/laravel-auth/tests');
```
