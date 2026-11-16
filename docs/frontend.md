# Frontend guide

This guide is for frontend developers who connect a web app, mobile app or other client to a Laravel API that uses `igne-agency/laravel-auth`. It covers how to read the API's endpoints and schemas, how to set up your client, every authentication flow and the errors to handle.

The package is headless: it has no screens. Every login, two-factor, password and session screen is yours to build on top of the JSON endpoints below.

## Contents

- [Before you start](#before-you-start)
- [See the endpoints and schemas](#see-the-endpoints-and-schemas)
- [Choose a mode](#choose-a-mode)
- [Session mode (browser SPA)](#session-mode-browser-spa)
- [Token mode (mobile and other clients)](#token-mode-mobile-and-other-clients)
- [The current user](#the-current-user)
- [Flows](#flows)
- [Errors](#errors)
- [Endpoint reference](#endpoint-reference)
- [Troubleshooting](#troubleshooting)

## Before you start

Ask the backend team for:

| What | Why |
|---|---|
| The API base URL, e.g. `https://api.example.com/api` | All package routes live under the `api` prefix by default. |
| Which mode your client uses | Session mode (cookies) for a browser SPA on the same site, token mode for everything else. See [Choose a mode](#choose-a-mode). |
| For session mode: your SPA's host and port registered as a stateful domain | Without it, the API treats your SPA as a token client. |
| The frontend URLs the mails link to | The password reset mail and the login code mail link to pages in your app (see [Forgot and reset password](#forgot-and-reset-password) and [Log in with an emailed code](#log-in-with-an-emailed-code)). |
| Access to the API docs, and whether two-factor authentication is required | See [See the endpoints and schemas](#see-the-endpoints-and-schemas). With `two_factor.required`, every user must set up 2FA before they can use the app. |

## See the endpoints and schemas

The API describes every endpoint in an OpenAPI 3.1 document, generated with [Scramble](https://scramble.dedoc.co). It has the request bodies, the response schemas, the error responses and which authentication each route needs. The package checks in its own tests that its responses match that document, so you can rely on it.

There are three ways to read it:

1. **Interactive docs in the browser.** Scramble serves them on the API at `/docs/api`, and the raw document at `/docs/api.json`. Locally they are open. On other environments the app decides who may see them (Scramble's `viewApiDocs` gate); the route also runs the app's two-factor enforcement, so log in to the app first (with two-factor authentication passed, if required). Ask the backend team for access.
2. **The committed `api.json`.** The app's repository commits the exported document (usually `api.json` in its root), and its CI fails when the file is out of date. Use that file to generate code, so your client matches the backend version you build against.
3. **Generated TypeScript types.** Generate types from `api.json` and use a typed client, so a changed endpoint becomes a compile error instead of a runtime bug:

   ```bash
   npm install openapi-fetch
   npm install --save-dev openapi-typescript
   npx openapi-typescript ./api.json -o ./src/api/schema.d.ts
   ```

   ```ts
   import createClient from 'openapi-fetch';
   import type { paths } from './api/schema';

   export const api = createClient<paths>({
     baseUrl: 'https://api.example.com/api',
     credentials: 'include', // session mode only, see below
   });

   const { data, error, response } = await api.GET('/user');
   ```

   Run the generator again whenever the backend updates `api.json` (add it as an npm script, e.g. `"api:types": "openapi-typescript ../backend/api.json -o src/api/schema.d.ts"`).

Paths in the document are relative to its `servers` entry (the `api` prefix): the document says `/auth/login`, the URL is `https://api.example.com/api/auth/login`. The document's security per operation tells you which routes need a logged-in user: `bearer` (token mode) or `session` plus `xsrf` (session mode).

## Choose a mode

The package supports two kinds of clients on the same endpoints. The API decides the mode per request: a request from a registered stateful domain (your SPA) runs in session mode, every other request in token mode.

| | Session mode | Token mode |
|---|---|---|
| For | A browser SPA on the same site as the API (e.g. `app.example.com` and `api.example.com`) | Mobile apps, desktop apps, other domains, server-to-server |
| You are logged in by | An HTTP-only session cookie | An access token in the `Authorization` header |
| CSRF protection | `GET /sanctum/csrf-cookie`, then the `X-XSRF-TOKEN` header | Not needed |
| A remembered device is | The `two_factor_device` cookie, handled by the browser | A `device_token` you store and send as `X-Two-Factor-Device` |
| You must store | Nothing | The access token and the device token |

Prefer session mode for a browser app: the browser keeps the session cookie out of JavaScript's reach, while a token in `localStorage` can be read by any script that runs on your page.

## Session mode (browser SPA)

### Backend setup

The backend team sets this up once (see [SPA cookie clients](clients.md#spa-cookie-clients)):

- your SPA's host, with its port, in `SANCTUM_STATEFUL_DOMAINS` (e.g. `app.example.com`, or `localhost:5173` for local development);
- `SESSION_DOMAIN` so the session cookie reaches the API from your SPA, e.g. `.example.com`;
- CORS with `supports_credentials` and your SPA's origin allowed.

Your SPA and the API must be on the same site (the same registrable domain). A SPA on `app.other.com` calling `api.example.com` can't use session mode: use token mode there.

### Client setup

1. Send every request with credentials, so the browser sends and stores cookies.
2. Call `GET /sanctum/csrf-cookie` once before the first state-changing request (login), and again after a 419 response. Note that this route is outside the `/api` prefix: `https://api.example.com/sanctum/csrf-cookie`.
3. Send the `XSRF-TOKEN` cookie's value back in the `X-XSRF-TOKEN` header on every `POST`, `PUT` and `DELETE`.
4. Send `Accept: application/json`, so the app's own routes answer with JSON too (the package's routes always do).

With axios:

```ts
import axios from 'axios';

export const http = axios.create({
  baseURL: 'https://api.example.com/api',
  withCredentials: true,
  withXSRFToken: true, // axios 1.6.2+: also send X-XSRF-TOKEN cross-origin
  headers: { Accept: 'application/json' },
});

await axios.get('https://api.example.com/sanctum/csrf-cookie', { withCredentials: true });
```

With `fetch` or `openapi-fetch`, add the header yourself:

```ts
api.use({
  onRequest({ request }) {
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];
    if (xsrf) {
      request.headers.set('X-XSRF-TOKEN', decodeURIComponent(xsrf));
    }
    request.headers.set('Accept', 'application/json');
    return request;
  },
});
```

In session mode, the login answers `{"two_factor": false}` without a token. If you get a `token` back, the API didn't recognise your SPA as stateful: see [Troubleshooting](#troubleshooting).

## Token mode (mobile and other clients)

1. Log in with `POST /auth/login`. Pass `device_name` (e.g. "iPhone of Sam") so users recognise the token in their session list; the user agent is used otherwise. `remember` is ignored in token mode.
2. Store the `token` in secure storage (Keychain, Android Keystore). Send it on every request as `Authorization: Bearer <token>`.
3. `expires_at` is when the token stops working (`null` means it doesn't expire). The API can also expire a token that wasn't used for a while. Either way you get 401: send the user to the login screen.
4. Store the `device_token` from the two-factor challenge (when it isn't `null`) and send it as `X-Two-Factor-Device` with the next login, so the user skips the two-factor challenge on this device.
5. `POST /auth/logout` revokes the token. Delete it from storage afterwards (keep the device token).

You never need CSRF tokens or cookies in token mode.

## The current user

`GET /user` returns the logged-in user, unwrapped (no `data` key):

```json
{
  "id": 1,
  "email": "sam@example.com",
  "confirmation_method": "password",
  "two_factor_enabled": true,
  "two_factor_required": true
}
```

| Field | Use it to |
|---|---|
| `confirmation_method` | Pick the screen for confirming the user's identity: `password`, `totp` (a code from their authenticator app), `login_code` (an emailed code) or `null` (they can't confirm). See [Confirm the user's identity](#confirm-the-users-identity). |
| `two_factor_enabled` | Show whether two-factor authentication is set up. |
| `two_factor_required` | Send a user with `two_factor_required: true` and `two_factor_enabled: false` to your two-factor setup screen right after login. |

The app may add its own fields to this response; check the OpenAPI document. `GET /user` always works for a logged-in user, also before two-factor setup or the challenge, so call it after login to decide where to go.

## Flows

All examples use the paths without the `/api` prefix, like the OpenAPI document.

### Log in with a password

```http
POST /auth/login
{ "email": "sam@example.com", "password": "secret", "remember": true }
```

| Answer | Meaning | Next |
|---|---|---|
| 200 `{"two_factor": false}` (session) | Logged in. | `GET /user`. |
| 200 `{"two_factor": false, "token": "…", "expires_at": "…"}` (token) | Logged in. | Store the token, `GET /user`. |
| 200 `{"two_factor": true}` (session) or `{"two_factor": true, "challenge": "…"}` (token) | Password correct, the device must pass the two-factor challenge. | [Pass the two-factor challenge](#pass-the-two-factor-challenge). |
| 422 `errors.email` | Wrong email or password. Show one generic message. | |
| 429 | Too many attempts. | Wait `Retry-After` seconds. |

`remember` keeps a session-mode user logged in after the browser closes.

### Pass the two-factor challenge

After `"two_factor": true`, ask for a six-digit code from the authenticator app (or a recovery code) and post it:

```http
POST /auth/two-factor-challenge
X-Auth-Challenge: <challenge>          (token mode only)
{ "code": "123456", "remember_device": true, "device_name": "iPhone of Sam" }
```

Send `recovery_code` instead of `code` when the user lost their authenticator. A recovery code works once.

| Answer | Meaning |
|---|---|
| 204 (session) | Logged in. A remembered device got the `two_factor_device` cookie. |
| 200 `{"token", "expires_at", "device_token"}` (token) | Logged in. Store the token and, when it isn't `null`, the device token. |
| 422 `errors.code` (or `errors.recovery_code`) | Wrong code, or the challenge expired. |
| 429 | Too many codes: five per minute. |

In token mode the `challenge` id works once and expires after about ten minutes (`header_sessions.challenge_lifetime_minutes`); after that, start again at the login. `remember_device` decides how long the device stays trusted (the backend configures it, never longer than 365 days); without it, trust lasts as long as the session or token, or a configured number of days.

### Log in with an emailed code

Some users have no password and log in with a one-time code from their email:

1. `POST /auth/login-code` with `{ "email": "…" }`. It always answers 202 with the same message, also for unknown addresses and when rate-limited, so your screen must not say whether the account exists. Show something like "If the address is known, we sent a code".
2. The user types the code. Post it as the password: `POST /auth/login` with `{ "email": "…", "password": "<code>" }`. The answers are the same as a password login, including the two-factor challenge.

A code expires (ten minutes by default), works once and allows a few wrong attempts. When the backend sets `login_codes.link_url`, the mail also links to a page in your app with the email and the code in the URL (e.g. `https://app.example.com/login/code?email={email}&code={code}`); that page posts them to `POST /auth/login`.

Whether a user logs in with a password or a code is decided by the backend. A screen that asks for a password can also accept a code; offer "Email me a code" as the alternative.

### Log out

`POST /auth/logout` answers 204. Session mode ends the session; token mode revokes the token. Afterwards, call `GET /sanctum/csrf-cookie` again before the next login in session mode.

### Handle required two-factor authentication

Every protected route of the app, not only the package's, can answer:

- **403 `two_factor_setup_required`:** the user must set up two-factor authentication first. Send them to your setup screen.
- **403 `two_factor_required`:** this device did not pass the challenge (for example after the device's trust expired). Ask for a code and post it to `POST /auth/two-factor-challenge` while logged in (with the session or the bearer token, no `X-Auth-Challenge`). That step-up answers 204 in session mode and `{"device_token"}` in token mode. Then retry the request.

Handle both in one place, in your HTTP client's response interceptor.

### Set up two-factor authentication

1. `POST /user/two-factor-authentication` (optionally `{ "force": true }` to replace an unconfirmed setup). It may answer 423: confirm the user's identity first and retry.
2. `GET /user/two-factor-qr-code` returns `{ "svg": "<svg…>", "url": "otpauth://…" }`. Render the SVG, and offer the `url` as a link for apps on the same device.
3. `GET /user/two-factor-secret-key` returns `{ "secretKey": "…" }` for users who type the key into their app.
4. The user enters a code from the app: `POST /user/confirmed-two-factor-authentication` with `{ "code": "123456" }`. A wrong code answers 422 `errors.code`.
5. `GET /user/two-factor-recovery-codes` returns the recovery codes as an array of strings. Show them once and ask the user to store them.

Until step 4 succeeds, `two_factor_enabled` stays `false`. Other routes:

- `POST /user/two-factor-recovery-codes` replaces the recovery codes (then fetch them again);
- `DELETE /user/two-factor-authentication` turns two-factor authentication off (not allowed when the app requires it: the next request answers `two_factor_setup_required`).

These routes need a recent identity confirmation (423) and, when two-factor authentication is on, a device that passed the challenge (403 `two_factor_required`).

### Confirm the user's identity

Sensitive actions (two-factor settings, revoking devices and sessions) answer **423** when the user hasn't confirmed their identity recently. Ask for confirmation, then retry the request:

| `confirmation_method` | Ask for | Then |
|---|---|---|
| `password` | Their password | `POST /user/confirm-password` with `{ "password": "…" }` |
| `totp` | A code from their authenticator app | `POST /user/confirm-password` with `{ "password": "<code>" }` |
| `login_code` | An emailed code | First `POST /user/confirm-password/code` (202), then `POST /user/confirm-password` with `{ "password": "<code>" }` |

A confirmation answers 201 and lasts for a while (three hours by default). `GET /user/confirmed-password-status` returns `{ "confirmed": true|false }` (pass `?seconds=` to ask about a shorter window), so you can ask up front instead of waiting for a 423.

### Forgot and reset password

1. `POST /auth/forgot-password` with `{ "email": "…" }`. It always answers 200 with the same message for a valid address, so again don't reveal whether the account exists. Users without a password get no mail.
2. The mail links to your reset page with a token and the email (the backend sets the URL, e.g. `https://app.example.com/reset-password?token={token}&email={email}`).
3. Your page posts `POST /auth/reset-password` with `{ "token", "email", "password", "password_confirmation" }`. 200 means done: send the user to the login. 422 `errors.email` means the link is invalid or expired; 422 `errors.password` lists the password rules that failed.

The reset does not log the user in.

### Change password

`PUT /user/password` with `{ "current_password", "password", "password_confirmation" }` answers 200. A wrong current password answers 422 `errors.current_password`; a too-weak password 422 `errors.password`. A user without a password gets 403 `password_unavailable`: show the screen only when `confirmation_method` is `password` (users with a password always confirm with it).

### Manage devices and sessions

- `GET /user/devices` returns `{ "data": [TrustedDevice] }`: the devices that skip the two-factor challenge. `remembered` tells whether the user ticked "remember this device"; `is_current` marks the device of this request.
- `GET /user/sessions` returns `{ "data": [ActiveSession] }`: cookie sessions and access tokens. `type` is `session` or `token`; `is_current` marks this request's own session or token.
- `DELETE /user/devices/{id}`, `DELETE /user/sessions/{id}` and `DELETE /user/sessions` (all other sessions) answer 204. They need a recent identity confirmation (423).
- Revoking the current session or token answers 403 `current_session`: use logout for that, and hide the button for the row with `is_current: true`.

The schemas of `TrustedDevice` and `ActiveSession` are in the OpenAPI document.

## Errors

| Status | Body | When | What to do |
|---|---|---|---|
| 401 | `{ "message" }` | Not logged in, or the token expired. | Clear the stored token and go to the login. |
| 403 | `{ "message", "code" }` | See the codes below. | Act on `code`, never on `message`. |
| 404 | `{ "message" }` | The device or session doesn't exist (anymore). | Refresh the list. |
| 419 | `{ "message" }` | Session mode: the CSRF token is missing or expired. | Call `GET /sanctum/csrf-cookie` and retry once. |
| 422 | `{ "message", "errors": { "field": ["…"] } }` | Validation failed, wrong credentials or code. | Show `errors` next to the fields. |
| 423 | `{ "message" }` | The action needs a recent identity confirmation. | [Confirm the user's identity](#confirm-the-users-identity), then retry. |
| 429 | `{ "message" }` | Too many attempts. | Wait the `Retry-After` header's seconds. |

The `code` values of a 403:

| `code` | Meaning |
|---|---|
| `two_factor_setup_required` | The user must set up two-factor authentication first. |
| `two_factor_required` | This device must pass the two-factor challenge (step-up). |
| `password_unavailable` | The user has no password, so they can't change it. |
| `login_code_unavailable` | The user can't confirm their identity with an emailed code. |
| `current_session` | The current session or token can't be revoked; log out instead. |

The `message` texts are translated into the app's language. Show them as they are, or map `code` and the field names to your own texts; never parse the texts.

## Endpoint reference

Paths are relative to the API base URL (`…/api`). "Auth" means the route needs a logged-in user (a session or a bearer token). The OpenAPI document is the source of truth for every body and schema.

| Method | Path | Auth | Body | Success |
|---|---|---|---|---|
| POST | `/auth/login` | no | `email`, `password`, `remember?`, `device_name?`; header `X-Two-Factor-Device?` | 200 `{two_factor, challenge?, token?, expires_at?}` |
| POST | `/auth/two-factor-challenge` | challenge or step-up | `code` or `recovery_code`, `remember_device?`, `device_name?`; header `X-Auth-Challenge?` | 204, or 200 `{token?, expires_at?, device_token}` |
| POST | `/auth/login-code` | no | `email` | 202 `{message}` |
| POST | `/auth/logout` | yes | | 204 |
| POST | `/auth/forgot-password` | no | `email` | 200 `{message}` |
| POST | `/auth/reset-password` | no | `token`, `email`, `password`, `password_confirmation` | 200 `{message}` |
| GET | `/user` | yes | | 200 `Authenticatable` |
| PUT | `/user/password` | yes | `current_password`, `password`, `password_confirmation` | 200 |
| POST | `/user/confirm-password` | yes | `password` (or a code) | 201 |
| GET | `/user/confirmed-password-status` | yes | query `seconds?` | 200 `{confirmed}` |
| POST | `/user/confirm-password/code` | yes | | 202 `{message}` |
| POST | `/user/two-factor-authentication` | yes, confirmed | `force?` | 200 |
| DELETE | `/user/two-factor-authentication` | yes, confirmed | | 200 |
| POST | `/user/confirmed-two-factor-authentication` | yes, confirmed | `code` | 200 |
| GET | `/user/two-factor-qr-code` | yes, confirmed | | 200 `{svg, url}` |
| GET | `/user/two-factor-secret-key` | yes, confirmed | | 200 `{secretKey}` |
| GET | `/user/two-factor-recovery-codes` | yes, confirmed | | 200 `string[]` |
| POST | `/user/two-factor-recovery-codes` | yes, confirmed | | 200 |
| GET | `/user/devices` | yes | | 200 `{data: TrustedDevice[]}` |
| DELETE | `/user/devices/{trustedDevice}` | yes, confirmed | | 204 |
| GET | `/user/sessions` | yes | | 200 `{data: ActiveSession[]}` |
| DELETE | `/user/sessions/{session}` | yes, confirmed | | 204 |
| DELETE | `/user/sessions` | yes, confirmed | | 204 |

"Confirmed" means a recent identity confirmation; without one the route answers 423. Some routes only exist when the backend enabled the feature (password reset and change, two-factor authentication).

## Troubleshooting

| Symptom | Cause |
|---|---|
| The SPA's login returns a `token` | The request didn't count as stateful: your host and port aren't in `SANCTUM_STATEFUL_DOMAINS`, or the request has no `Origin`/`Referer` header. |
| 419 on login | `GET /sanctum/csrf-cookie` wasn't called, the `X-XSRF-TOKEN` header is missing, or the request was sent without credentials. |
| The SPA logs in but the next request is 401 | The session cookie doesn't reach the API: check `SESSION_DOMAIN`, CORS `supports_credentials`, and that every request is sent with credentials. |
| The challenge answers 422 after a while | The `challenge` id expired or was already used. Log in again. |
| Every request answers 403 `two_factor_setup_required` | The app requires two-factor authentication and the user hasn't set it up. Only `GET /user`, logout, identity confirmation and the two-factor setup routes work until they do. |
| The two-factor challenge is asked on every login | Token mode: send the stored `device_token` as `X-Two-Factor-Device`. Session mode: the browser must keep the `two_factor_device` cookie, and the user must tick "remember this device". |
| The OpenAPI document doesn't match the API | Regenerate your types from the current `api.json` of the backend version you run against. |
