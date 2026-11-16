# igne-agency/laravel-auth

Headless authentication for Laravel apps, on top of [Laravel Fortify](https://github.com/laravel/fortify) and [Sanctum](https://github.com/laravel/sanctum). The package registers JSON routes that run Fortify's own controllers, for two kinds of clients:

- **Session mode:** an SPA whose requests run through Sanctum's stateful stack logs in to its cookie session.
- **Token mode:** every other client gets an access token from the same login route, and sends it as a bearer token. A header session (keyed by the token, or by the `X-Auth-Challenge` header before login) lets Fortify's session-based flows, such as password confirmation, run unchanged.

The package ships no views. Screens belong to the frontends.

[Concepts](docs/concepts.md) explains the words IGNE projects use for authentication and authorization (identity, provider, space, role, permission, scope, audit log), what this package builds of them, the naming to use and avoid, and the authorization layers a request passes.

The [frontend guide](docs/frontend.md) is for frontend developers: how to read the endpoints and schemas, set up a session-mode SPA or a token client, every authentication flow, and the errors to handle.

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
| Nova's login, challenge and security screens | When Nova 5 is installed | Automatic; see [Nova](#nova). |
| OpenAPI document of every package route | When Scramble 0.13 is installed | Automatic; see [OpenAPI](#openapi). |

## Requirements

- PHP 8.4
- Laravel 13
- laravel/fortify 1.42 or later, laravel/sanctum 4.4 or later

## Install

The package is not on Packagist, so add its repository first:

```bash
composer config repositories.igne-laravel-auth vcs https://github.com/IGNE-Agency/laravel-auth
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

## Configuration

`config/authentication.php` documents every key:

| Key | Sets |
|---|---|
| `routes` | Whether the package registers its routes, their prefix (`api`) and their middleware (`['api']`). |
| `auth_resource` | The resource `GET user` answers with. |
| `login_codes` | Code length, lifetime, attempt and send limits, `link_url` (`AUTH_LOGIN_CODE_URL`), and the model, notification, generator and actions. |
| `two_factor` | `required`, how long a device stays trusted (`device_trust`), the device cookie and header, the exemptions, the reset notification and the device model. |
| `tokens` | The token issuer, the absolute lifetime (`expires_in_minutes`) and the idle lifetime (`idle_expires_in_minutes`). |
| `header_sessions` | The challenge header, the cache store and the lifetimes of token clients' header sessions. |
| `passwords` | `reset_url` (`AUTH_PASSWORD_RESET_URL`) and the reset notification. |
| `audit` | Whether the audit log is on, its model, `prune_after_days` and the record action. |
| `openapi` | The session cookie name in the OpenAPI document. |
| `schedule` | Whether the package schedules its daily pruning. |

The settings projects change most, with their defaults:

| Setting | Default | Sets |
|---|---|---|
| `two_factor.required` | `true` | Whether users must set up two-factor authentication before they can use the app. |
| `two_factor.device_trust.expires_in_days` | `'session'` | How long a device stays trusted after the challenge: the cookie session or access token, or 1 to 365 days. |
| `two_factor.device_trust.remember_expires_in_days` | `30` | The same, when the user ticks "remember device". |
| `two_factor.device_trust.extend_on_use` | `false` | Whether each use extends the trust, up to 365 days after the last challenge. |
| `tokens.expires_in_minutes` | `43200` (30 days) | The absolute lifetime of an access token; `null` for none. |
| `tokens.idle_expires_in_minutes` | `null` | Rejects an access token unused for that long; `null` for no idle limit. |
| `header_sessions.lifetime_minutes` | `SESSION_LIFETIME` (120) | The lifetime of a token client's header session, which holds state such as a password confirmation. |
| `login_codes.default_expires_in_minutes` | `10` | How long an emailed code works. |
| `audit.prune_after_days` | `365` | How long audit entries are kept; `null` keeps them. |
| `passwords.reset_url`, `login_codes.link_url` | `AUTH_PASSWORD_RESET_URL`, `AUTH_LOGIN_CODE_URL` | The frontend pages the mails link to. |

A cookie session lasts Laravel's own `session.lifetime` (`SESSION_LIFETIME`).

The provider validates `two_factor.device_trust` at boot and throws `InvalidConfigurationException` for a lifetime that isn't `'session'` or 1 to 365 days. Fortify's own options stay in `config/fortify.php`.

Everything the package sends to users is a notification named in the config (`login_codes.notification`, `two_factor.reset_notification`, `passwords.reset_notification`). Swap one for a notification of your own with the same constructor. The strings are in the `authentication::messages` translations (publish tag `authentication-lang`).

## SPA cookie clients

An SPA logs in to a cookie session when its requests run through Sanctum's stateful stack. For that:

- call `$middleware->statefulApi()` in `bootstrap/app.php`;
- list the SPA's host, with its port, in `sanctum.stateful` (`SANCTUM_STATEFUL_DOMAINS`);
- set `SESSION_DOMAIN` so the session cookie reaches the API from the SPA's domain, e.g. `.example.com`;
- publish the CORS config (`php artisan config:publish cors`), set `supports_credentials` to `true` and allow the SPA's origin;
- let the SPA call `GET /sanctum/csrf-cookie` first, and send its requests with credentials and the `X-XSRF-TOKEN` header.

`POST api/auth/login` then answers `{"two_factor": false}`, or `{"two_factor": true}` when the SPA must post a code to `POST api/auth/two-factor-challenge`. A remembered device gets the `two_factor_device` cookie.

## Token clients

Every other client is in token mode:

1. `POST api/auth/login` with `email`, `password` and optionally `device_name` (the token's name; the user agent by default). It answers `{"two_factor": false, "token": "…", "expires_at": "…"}`, or `{"two_factor": true, "challenge": "…"}`. `remember` is ignored.
2. After a challenge, `POST api/auth/two-factor-challenge` with the `X-Auth-Challenge` header set to the challenge, a `code` (or `recovery_code`) and optionally `remember_device` and `device_name`. It answers `{"token", "expires_at", "device_token"}`. A challenge works once and expires after `header_sessions.challenge_lifetime_minutes`.
3. Send `Authorization: Bearer <token>` from then on. Keep the `device_token` (null unless trust outlasts the token), and send it as `X-Two-Factor-Device` with the next login to skip the challenge.
4. On 403 `two_factor_required`, post a code to the challenge route with the bearer token. That step-up answers `{"device_token"}`.
5. On 423, confirm with `POST api/user/confirm-password`. The header session is keyed by the token, so the client sends nothing extra.
6. `POST api/auth/logout` revokes the current token.

## Login codes

Users that implement `LoginCodeAuthenticatable` can log in with an emailed one-time code instead of a password. `POST auth/login-code` sends one; the login route takes it in the `password` field. A user logs in with their password when they can use one, and else (or when it doesn't match) with a code when `canAuthenticateWithLoginCode()` allows it. Override that method to limit codes, e.g. to users without a password, so password users can't skip their password with a code.

The request route always answers 202 with the same body, also for unknown addresses and when it is rate-limited, so it never reveals whether an account exists. Only an HMAC of a code is stored (keyed with the app key; codes made under a key in `app.previous_keys` keep working). A code expires, works once and takes a few wrong attempts; a user's wrong attempts across codes are rate-limited too, and a new code does not reset that.

Logged-in users confirm their identity (Fortify's confirm-password route and `password.confirm` middleware) with the strongest factor they have: their password, else a code from their authenticator app, or else an emailed code from `POST user/confirm-password/code`. `GET user` tells which one in `confirmation_method`.

The mail is `Igne\Auth\LoginCodes\Notifications\LoginCodeNotification`, a queued `MailMessage`. Swap it in `login_codes.notification` for a notification with the same constructor (`string $code, CarbonInterface $expiresAt, ?string $url, LoginCodePurpose $purpose`). With `login_codes.link_url` set, the login mail also links to that frontend page, with the `{email}` and `{code}` placeholders filled in; the page posts the code to the login route.

## Two-factor authentication

Users that implement `TwoFactorAuthenticatable` get Fortify's TOTP two-factor authentication, asked again per device. The login answers `"two_factor": true` (plus a `challenge` id in token mode, for the `X-Auth-Challenge` header) and the client passes `POST auth/two-factor-challenge` next. A remembered device skips the challenge: its device token comes back as the `two_factor_device` cookie (session mode) or as `device_token` for the `X-Two-Factor-Device` header (token mode). `two_factor.device_trust` sets how long a device stays trusted, never more than 365 days after its last challenge.

`EnsureTwoFactorAuthenticated` runs in the `web` and `api` groups, right after authentication. With `two_factor.required`, a user without two-factor authentication gets 403 `two_factor_setup_required`; a user on a device that did not pass the challenge gets 403 `two_factor_required` and steps up on the challenge route with their session or access token. List routes that skip the checks in `two_factor.except` and `two_factor.except_setup`, or call `->withoutMiddleware()`. `TwoFactorEnforcement::respondUsing()` registers a responder for screens of your own; with Nova installed, Nova's login, challenge and User Security screens handle it.

`php artisan authentication:two-factor:reset {user}` (and the Nova action `Igne\Auth\Nova\Actions\ResetTwoFactorAuthentication`, behind the `resetTwoFactorAuthentication` gate) resets the two-factor authentication of a user who lost their authenticator, logs them out everywhere and sends `two_factor.reset_notification`.

## Devices and sessions

Users manage where they are logged in themselves. `GET user/devices` lists the devices they passed the two-factor challenge on; `GET user/sessions` lists their cookie sessions (only with the `database` session driver) and access tokens, with ids such as `session:{sha256 of the session id}` and `token:{id}`, never a raw session id. Both mark the one the request comes from with `is_current`. Revoking a device, a session or all other sessions needs a recent password confirmation (`password.confirm`, 423). The current session or access token can't be revoked there (403 `current_session`): logging out ends it. Revoking a cookie session also cycles the user's remember token, so a "remember me" cookie can't log the browser back in.

With `two_factor.device_trust.expires_in_days` set to `'session'`, a cookie session's trust is a flag in that session, so it never appears in `GET user/devices`; ending the session ends the trust. A token client's trust for `'session'` is a device bound to its access token, listed until the token goes.

## Audit log

The package writes security events to the append-only `audit_logs` table (`Igne\Auth\Audit\Models\AuditLog`): logins and failed logins, lockouts, logouts, login codes, two-factor events, trusted and revoked devices, created and revoked access tokens, revoked sessions and password changes and resets. Each entry has the actor and the account (subject), the channel, the factors proved in the request (`auth_methods`), the IP address, the user agent and an id shared by the request's entries. It never holds credentials: a failed login keeps only the username (`metadata.username`), and sessions and access tokens are identified by the SHA-256 hash of their id (`credential_hash`). Logins restored from a "remember me" cookie are not logged.

Reading the entries:

- The actor is the request's authenticated user, else the user the event is about when they act themselves (such as logging in), else null for system actions (such as a two-factor reset from the console).
- `auth_methods` holds the factors proved in that request. After a two-factor challenge, `login.succeeded` holds only `["totp"]` (or `["recovery_code"]`); the first factor is on the earlier `two_factor.challenged` entry.
- `channel`, `auth_methods`, the IP address, the user agent and `request_id` are only set for HTTP requests.

Entries can't be changed or deleted through the model; `model:prune` deletes them after `audit.prune_after_days`. That guard runs on model events, so bulk query updates and deletes (`AuditLog::query()->update()`) bypass it: don't use them.

Extend `Igne\Auth\Audit\Actions\RecordAuditLogAction` and set `audit.actions.record_audit_log` to send entries elsewhere too, such as to a SIEM, or switch the log off with `audit.enabled`.

## Routes

With the default prefix `api`:

| Method | URI | Name |
|---|---|---|
| POST | `auth/login` | `authentication.login` |
| POST | `auth/logout` | `authentication.logout` |
| POST | `auth/login-code` | `authentication.login-code` |
| POST | `auth/two-factor-challenge` | `authentication.two-factor.challenge` |
| POST | `auth/forgot-password` | `authentication.password.email` |
| POST | `auth/reset-password` | `authentication.password.update` |
| GET | `user` | `authentication.user` |
| PUT | `user/password` | `authentication.user-password.update` |
| POST | `user/confirm-password` | `authentication.password.confirm.store` |
| GET | `user/confirmed-password-status` | `authentication.password.confirmation` |
| POST | `user/confirm-password/code` | `authentication.password.confirm.code` |
| POST, DELETE | `user/two-factor-authentication` | `authentication.two-factor.enable`, `.disable` |
| POST | `user/confirmed-two-factor-authentication` | `authentication.two-factor.confirm` |
| GET | `user/two-factor-qr-code`, `user/two-factor-secret-key` | `authentication.two-factor.qr-code`, `.secret-key` |
| GET, POST | `user/two-factor-recovery-codes` | `authentication.two-factor.recovery-codes`, `.regenerate-recovery-codes` |
| GET | `user/devices` | `authentication.devices.index` |
| DELETE | `user/devices/{trustedDevice}` | `authentication.devices.destroy` |
| GET | `user/sessions` | `authentication.sessions.index` |
| DELETE | `user/sessions/{session}`, `user/sessions` | `authentication.sessions.destroy`, `.destroy-others` |

The password routes follow Fortify's `resetPasswords` and `updatePasswords` features, and the two-factor and device routes its `twoFactorAuthentication` feature. The password reset and change routes run thin subclasses of Fortify's controllers that validate the input with `ResetPasswordRequest` and `UpdatePasswordRequest` first; the strength of a new password comes from the app's `Password::defaults()`.

Protected routes of the app belong in the `web` or `api` middleware group, so the two-factor enforcement applies to them. Exempt a route only deliberately, through `two_factor.except` or `two_factor.except_setup`, `TwoFactorEnforcement::except()` or `exceptSetup()`, or `->withoutMiddleware(EnsureTwoFactorAuthenticated::class)`.

## Nova

With [Laravel Nova](https://nova.laravel.com) 5 installed, `Igne\Auth\Nova\NovaIntegration` lets Nova's own screens handle authentication:

- Nova's login, logout, challenge, password reset pages and assets skip the two-factor enforcement, and its User Security page skips the setup check;
- a Nova user without two-factor authentication goes to User Security, and one on an untrusted device is logged out of the session (which stays) and sent to Nova's challenge;
- a route named `password.confirm` redirects to Nova's password confirmation page, unless the app defines one.

Nova logs in through `Fortify::authenticateUsing`, so the credential check and the per-device challenge apply to it. In the app:

- enable the same `updatePasswords` and `twoFactorAuthentication` features in `NovaServiceProvider::fortify()`;
- set Fortify's `limiters.two-factor` to `authentication-two-factor`, so Nova's challenge uses the package limiter;
- add the action `Igne\Auth\Nova\Actions\ResetTwoFactorAuthentication` to the user resource, and define the `resetTwoFactorAuthentication` gate (the package's default denies everyone).

Nova's challenge has no "remember" checkbox, so Nova logins trust a device for `two_factor.device_trust.expires_in_days`.

Nova is paid, so the package only suggests it. The package's own CI covers the adapter in its `nova` job when the repository has the `COMPOSER_AUTH` secret: it installs Nova, runs Larastan with `phpstan-nova.neon.dist` (which includes `src/Nova`) and runs the tests in `tests/Nova` on a Testbench Nova setup (`tests/Fixtures/Nova`). Without the secret, such as on pull requests from forks, the job skips those steps.

## Passport

Passport is not supported yet. The seam for it is the token issuer: `Igne\Auth\Tokens\Contracts\IssuesAccessTokens`, set in `tokens.issuer` (default `SanctumAccessTokenIssuer`). The package never adds `HasApiTokens` to the model, because Passport's trait conflicts with Sanctum's. The idle lifetime and the trust bound to an access token hook into Sanctum's token model, so a Passport issuer needs its own equivalents.

## OpenAPI

When the app uses [Scramble](https://scramble.dedoc.co) 0.13.48 or a later 0.13 release, the package documents its routes in the app's default API, in its OpenAPI 3.1 document:

- every package route has an explicit request and response schema (`Igne\Auth\OpenApi\Schemas\AuthenticationOperations`, keyed by route name), plus the error answers its middleware and body lead to (401, 403 with `code`, 422, 423, 429);
- every route behind `auth:sanctum` is secured by `bearer`, or by the `session` cookie plus the `xsrf` header;
- the package exceptions the app's own code throws are documented as `{message, code}`, with `code` from `Igne\Auth\Http\ErrorCode`.

Scramble stays optional. The package conflicts with Scramble versions outside that range (`"dedoc/scramble": "<0.13.48 || >=0.14"`), because Scramble's extension API changes between 0.x releases.

In the app, commit the exported document (`php artisan scramble:export`) and test that it is not stale; export it again after updating the package. Pin `openapi.session_cookie`, so every environment exports the same document.

In this package:

- The route schemas live in `src/OpenApi/Schemas`, next to the logic. Everything that calls Scramble lives in `Igne\Auth\OpenApi\Scramble` (`src/OpenApi/Scramble`), which `ScrambleIntegration` wires up when Scramble is installed.
- `tests/OpenApi/SpecTest.php` snapshots the part of the generated document the package owns: the operations of the `authentication.*` routes and the components. After changing a route's schema, refresh the snapshot with `composer openapi` and review its diff.
- Every HTTP test of a package route ends with `->assertMatchesOpenApi('<route name>')`. It fails when the response's status is not documented for that route, and validates a documented JSON body against its schema (JSON Schema 2020-12 with `opis/json-schema`). A failure means the schema or the response is wrong; fix that, not the assertion.

## AI guidelines

The package ships a [Laravel Boost](https://github.com/laravel/boost) guideline (`resources/boost/guidelines/core.blade.php`: the concepts, naming, layers and package rules) and the `authentication-development` skill (`resources/boost/skills/authentication-development`). Boost reads them from the installed package, when the app requires it directly. Boost only adds a newly installed package's guideline when you choose it: run `php artisan boost:update` (or `boost:install`) interactively and select `igne-agency/laravel-auth`. After that, `boost:update --no-interaction` keeps it up to date.

Change the guideline, the skill and [Concepts](docs/concepts.md) in the same pull request as the behaviour they describe.

## Tests

The package runs on its own, on Orchestra Testbench with Fortify, Sanctum and Scramble:

```bash
composer install
composer lint      # Pint
composer analyse   # Larastan, level max on src and tests/Fixtures, without the Nova code
composer test      # Pest
```

`tests/TestCase.php` configures the app the suite needs: an in-memory SQLite database, array cache, session and mail, and the fixture user in `tests/Fixtures`. Without Scramble or `opis/json-schema` installed, the snapshot test is skipped and `assertMatchesOpenApi` asserts nothing; CI runs the suite that way too.

CI (`.github/workflows/ci.yml`) runs three jobs on pull requests and pushes to `main`:

- `checks`: Pint, Larastan and Pest, on the lowest and the newest dependencies the constraints allow;
- `without-scramble`: Pest after removing Scramble and `opis/json-schema`, so the package also works without them;
- `nova`: Larastan with `phpstan-nova.neon.dist` and the tests in `tests/Nova`, with Nova installed through the `COMPOSER_AUTH` secret (skipped without it).

Without Nova, the tests in `tests/Nova` are skipped. With Nova credentials in your Composer auth, run the Nova checks locally, then revert `composer.json`:

```bash
composer config repositories.nova composer https://nova.laravel.com
composer require --dev laravel/nova:^5.11
vendor/bin/phpstan analyse -c phpstan-nova.neon.dist --memory-limit=1G
vendor/bin/pest tests/Nova
```
