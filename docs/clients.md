# Session and token clients

The backend side of the two kinds of clients. The [frontend guide](frontend.md) covers the same flows for the developers who build the clients.

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

## Passport

Passport is not supported yet. The seam for it is the token issuer: `Igne\Auth\Tokens\Contracts\IssuesAccessTokens`, set in `tokens.issuer` (default `SanctumAccessTokenIssuer`). The package never adds `HasApiTokens` to the model, because Passport's trait conflicts with Sanctum's. The idle lifetime and the trust bound to an access token hook into Sanctum's token model, so a Passport issuer needs its own equivalents.
