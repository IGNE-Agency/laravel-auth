# igne-agency/laravel-auth

Headless authentication for Laravel apps, on top of [Laravel Fortify](https://github.com/laravel/fortify) and [Sanctum](https://github.com/laravel/sanctum). The package registers JSON routes that run Fortify's own controllers, for two kinds of clients:

- **Session mode:** an SPA whose requests run through Sanctum's stateful stack logs in to its cookie session.
- **Token mode:** every other client gets an access token from the same login route, and sends it as a bearer token. A header session (keyed by the token, or by the `X-Auth-Challenge` header before login) lets Fortify's session-based flows, such as password confirmation, run unchanged.

The package ships no views. Screens belong to the frontends.

[Concepts](docs/concepts.md) explains the words IGNE projects use for authentication and authorization (identity, provider, space, role, permission, scope, audit log), what this package builds of them, the naming to use and avoid, and the authorization layers a request passes.

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
- for login codes, let it also implement `Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable` with the `AuthenticatesWithLoginCode` trait;
- for two-factor authentication, enable Fortify's `twoFactorAuthentication` feature, and let it implement `Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable` with the package's `InteractsWithTwoFactorAuthentication` trait, which includes Fortify's `TwoFactorAuthenticatable` trait.

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

## Nova

With [Laravel Nova](https://nova.laravel.com) 5 installed, `Igne\Auth\Nova\NovaIntegration` lets Nova's own login, challenge and User Security screens handle two-factor authentication (see above), and the action `Igne\Auth\Nova\Actions\ResetTwoFactorAuthentication` resets it from a user resource. Nova is paid, so the package only suggests it.

The package's own CI covers the adapter in its `nova` job when the repository has the `COMPOSER_AUTH` secret: it installs Nova, runs Larastan with `phpstan-nova.neon.dist` (which includes `src/Nova`) and runs the tests in `tests/Nova` on a Testbench Nova setup (`tests/Fixtures/Nova`). Without the secret, such as on pull requests from forks, the job skips those steps.

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
composer analyse   # Larastan, level max on src and tests/Fixtures, without the Nova code
composer test      # Pest
```

`tests/TestCase.php` configures the app the suite needs: an in-memory SQLite database, array cache, session and mail, and the fixture user in `tests/Fixtures`. Without Scramble or `opis/json-schema` installed, the snapshot test is skipped and `assertMatchesOpenApi` asserts nothing; CI runs the suite that way too.

Without Nova, the tests in `tests/Nova` are skipped. With Nova credentials in your Composer auth, run the Nova checks locally, then revert `composer.json`:

```bash
composer config repositories.nova composer https://nova.laravel.com
composer require --dev laravel/nova:^5.11
vendor/bin/phpstan analyse -c phpstan-nova.neon.dist --memory-limit=1G
vendor/bin/pest tests/Nova
```
