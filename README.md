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
php artisan vendor:publish --tag=authentication-migrations
php artisan vendor:publish --tag=sanctum-migrations
php artisan migrate
```

Then:

- set `views` to `false` in `config/fortify.php`;
- call `$middleware->statefulApi()` in `bootstrap/app.php`;
- let the authenticatable model (usually `User`) implement `Igne\Auth\Authentication\Contracts\PasswordAuthenticatable` with the `AuthenticatesWithPassword` trait, and use Sanctum's `HasApiTokens`;
- for login codes, let it also implement `Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable` with the `AuthenticatesWithLoginCode` trait.

## Login codes

Users that implement `LoginCodeAuthenticatable` can log in with an emailed one-time code instead of a password. `POST auth/login-code` sends one; the login route takes it in the `password` field. A user logs in with their password when they can use one, and else (or when it doesn't match) with a code when `canAuthenticateWithLoginCode()` allows it. Override that method to limit codes, e.g. to users without a password, so password users can't skip their password with a code.

The request route always answers 202 with the same body, also for unknown addresses and when it is rate-limited, so it never reveals whether an account exists. Only an HMAC of a code is stored (keyed with the app key; codes made under a key in `app.previous_keys` keep working). A code expires, works once and takes a few wrong attempts; a user's wrong attempts across codes are rate-limited too, and a new code does not reset that.

Logged-in users confirm their identity (Fortify's confirm-password route and `password.confirm` middleware) with the strongest factor they have: their password, or else an emailed code from `POST user/confirm-password/code`. `GET user` tells which one in `confirmation_method`.

The mail is `Igne\Auth\LoginCodes\Notifications\LoginCodeNotification`, a queued `MailMessage`. Swap it in `login_codes.notification` for a notification with the same constructor (`string $code, CarbonInterface $expiresAt, ?string $url, LoginCodePurpose $purpose`). With `login_codes.link_url` set, the login mail also links to that frontend page, with the `{email}` and `{code}` placeholders filled in; the page posts the code to the login route.

## Routes

With the default prefix `api`:

| Method | URI | Name |
|---|---|---|
| POST | `auth/login` | `authentication.login` |
| POST | `auth/logout` | `authentication.logout` |
| POST | `auth/login-code` | `authentication.login-code` |
| POST | `auth/forgot-password` | `authentication.password.email` |
| POST | `auth/reset-password` | `authentication.password.update` |
| GET | `user` | `authentication.user` |
| PUT | `user/password` | `authentication.user-password.update` |
| POST | `user/confirm-password` | `authentication.password.confirm.store` |
| GET | `user/confirmed-password-status` | `authentication.password.confirmation` |
| POST | `user/confirm-password/code` | `authentication.password.confirm.code` |

The password routes follow Fortify's `resetPasswords` and `updatePasswords` features. They run thin subclasses of Fortify's controllers that validate the input with `ResetPasswordRequest` and `UpdatePasswordRequest` first; the strength of a new password comes from the app's `Password::defaults()`.

## OpenAPI

When the app uses [Scramble](https://scramble.dedoc.co) 0.13.48 or a later 0.13 release, the package documents its routes in the app's default API: explicit request and response schemas per route, the `bearer`, `session` and `xsrf` security schemes, and the responses of the package exceptions the app's own code throws. Scramble stays optional; the package conflicts with Scramble versions outside that range, because its extension API changes between 0.x releases.

- The route schemas live in `src/OpenApi/Schemas`, next to the logic. Everything that calls Scramble lives in `Igne\Auth\OpenApi\Scramble`, which `ScrambleIntegration` wires up when Scramble is installed.
- `tests/OpenApi/SpecTest.php` snapshots the part of the generated document the package owns: the operations of the `authentication.*` routes and the components. After changing a route's schema, refresh the snapshot with `composer openapi` and review its diff.
- Every HTTP test of a package route ends with `->assertMatchesOpenApi('<route name>')`. It fails when the response's status is not documented for that route, and validates a documented JSON body against its schema (JSON Schema 2020-12 with `opis/json-schema`). A failure means the schema or the response is wrong; fix that, not the assertion.

## Tests

The package runs on its own, on Orchestra Testbench with Fortify, Sanctum and Scramble:

```bash
composer install
composer lint      # Pint
composer analyse   # Larastan, level max on src and tests/Fixtures
composer test      # Pest
```

`tests/TestCase.php` configures the app the suite needs: an in-memory SQLite database, array cache, session and mail, and the fixture user in `tests/Fixtures`. Without Scramble or `opis/json-schema` installed, the snapshot test is skipped and `assertMatchesOpenApi` asserts nothing; CI runs the suite that way too.
