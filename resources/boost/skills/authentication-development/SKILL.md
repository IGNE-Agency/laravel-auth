---
name: authentication-development
description: 'ACTIVATE when the user works on authentication through igne-agency/laravel-auth: login (password or emailed login codes), the two-factor challenge, trusted devices, two-factor enforcement and its 403 codes, password confirmation, password reset, access tokens and their lifetimes, header sessions (X-Auth-Challenge, X-Two-Factor-Device), the devices and sessions endpoints, the security audit log, or the auth OpenAPI schemas. Activate when the user mentions PasswordAuthenticatable, LoginCodeAuthenticatable, TwoFactorAuthenticatable, AuthenticateCredentials, ConfirmIdentity, EnsureTwoFactorAuthenticated, TwoFactorEnforcement, DeviceTrust, IssuesAccessTokens, AuditLog, config/authentication.php, or routes named authentication.*. Also activate when adding protected routes, choosing names for auth or authorization concepts (identity, provider, space, role, permission, scope), or sending auth mails. Do NOT activate for Socialite or non-auth Laravel features.'
license: MIT
metadata:
  author: igne-agency
---

# IGNE Authentication Development

`igne-agency/laravel-auth` is a headless authentication package on Fortify and Sanctum. It registers JSON routes (prefix `api`) that run Fortify's own controllers for two kinds of clients:

- **Session mode:** an SPA whose requests run through Sanctum's stateful stack logs in to its cookie session.
- **Token mode:** every other client gets an access token from the same login route. A header session (keyed by the token, or by `X-Auth-Challenge` before login) lets Fortify's session-based flows run unchanged.

The middleware stack decides the mode (`Igne\Auth\Http\Channel`), never a header the client sends.

## Documentation

- The package `README.md` covers install, config, SPA and token clients, Nova, Passport and OpenAPI.
- `docs/concepts.md` in the package explains the concepts, naming and authorization layers.
- `config/authentication.php` documents every option. Fortify's own options stay in `config/fortify.php`.
- Use `search-docs` for Fortify and Sanctum behaviour the package builds on.

## Usage

- **Routes:** run `php artisan route:list --name=authentication` to see the package routes.
- **Authenticatable contracts:** `Igne\Auth\Authentication\Contracts\PasswordAuthenticatable`, `Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable`, `Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable`.
- **Credential check:** `Igne\Auth\Authentication\AuthenticateCredentials`, registered with `Fortify::authenticateUsing`. It checks the password, else consumes a login code, and records the factors in the `authentication.methods` request attribute.
- **Identity confirmation:** `Igne\Auth\Authentication\ConfirmIdentity`, registered with `Fortify::confirmPasswordsUsing`, takes the strongest factor the user has: password, else TOTP code, else an emailed code.
- **Two-factor enforcement:** `Igne\Auth\TwoFactor\Http\Middleware\EnsureTwoFactorAuthenticated` is appended to the `web` and `api` groups. Exemptions and responders live on `Igne\Auth\TwoFactor\TwoFactorEnforcement`.
- **Tokens:** `Igne\Auth\Tokens\Contracts\IssuesAccessTokens` (default `SanctumAccessTokenIssuer`), set in `tokens.issuer`.
- **Audit:** `Igne\Auth\Audit\Listeners\AuditEventSubscriber` writes `Igne\Auth\Audit\AuditEvent` entries through `audit.actions.record_audit_log` to `Igne\Auth\Audit\Models\AuditLog`.
- **Errors:** package errors are `{message, code}` with `Igne\Auth\Http\ErrorCode`: `password_unavailable`, `login_code_unavailable`, `two_factor_setup_required`, `two_factor_required`, `current_session`.

## Workflows

### Let an authenticatable model log in

```
- [ ] Implement PasswordAuthenticatable with the AuthenticatesWithPassword trait, and use Sanctum's HasApiTokens
- [ ] For emailed codes, implement LoginCodeAuthenticatable with the AuthenticatesWithLoginCode trait (the model must be Notifiable)
- [ ] Override canAuthenticateWithLoginCode() when only some users may use codes (e.g. `return $this->password === null;`)
- [ ] For 2FA, enable Fortify's twoFactorAuthentication feature, implement TwoFactorAuthenticatable with the InteractsWithTwoFactorAuthentication trait, which includes Fortify's TwoFactorAuthenticatable
- [ ] Override requiresTwoFactorAuthentication() when not every user must set 2FA up (default: two_factor.required)
- [ ] Publish and run the package migrations (authentication-migrations) and Sanctum's
```

### Add a protected route

```
- [ ] Register it in the api (or web) group, behind auth:sanctum, so EnsureTwoFactorAuthenticated applies
- [ ] Authorize the general action on the route (can: middleware or Gate), the model in a Policy, and filter lists with a query scope
- [ ] Only exempt it from 2FA on purpose: TwoFactorEnforcement::except()/exceptSetup(), two_factor.except/except_setup, or ->withoutMiddleware(EnsureTwoFactorAuthenticated::class)
- [ ] Put sensitive changes behind password.confirm (423 until the user confirmed recently)
- [ ] Test the 403 two_factor_setup_required and two_factor_required answers, not only the happy path
```

### Change what users receive

```
- [ ] Extend or replace the notification and set it in config/authentication.php: login_codes.notification, two_factor.reset_notification or passwords.reset_notification
- [ ] Keep the constructor of the notification it replaces
- [ ] Set login_codes.link_url ({email}, {code}) or passwords.reset_url ({token}, {email}) to link to frontend pages
- [ ] Override strings in lang/vendor/authentication (tag authentication-lang) instead of hard-coding them
```

### Record a security event

```
- [ ] Dispatch the matching Igne\Auth\Events\* event (or let Laravel's or Fortify's auth event fire); the subscriber writes the entry
- [ ] Never store credentials, raw session ids or tokens; identify sessions and tokens by AuditEventSubscriber::hashCredential()
- [ ] Never update or delete audit_logs rows; pruning is model:prune after audit.prune_after_days
- [ ] To send entries elsewhere too (e.g. a SIEM), extend RecordAuditLogAction and set audit.actions.record_audit_log
```

### Change a package route

```
- [ ] Keep it pointed at Fortify's controller when Fortify has one
- [ ] Give it an explicit schema in Igne\Auth\OpenApi\Schemas\AuthenticationOperations, keyed by route name
- [ ] Regenerate the app's api.json (composer openapi) so its stale-check test passes
```

## Client flows

**Session mode (SPA):** `GET /sanctum/csrf-cookie`, then `POST /api/auth/login` with `email` and `password` (the password or a login code). The answer is `{two_factor: false}`, or `{two_factor: true}` followed by `POST /api/auth/two-factor-challenge` with `code` or `recovery_code` and optionally `remember_device`. A remembered device gets the `two_factor_device` cookie.

**Token mode:** `POST /api/auth/login` (optionally `device_name`) answers `{two_factor: false, token, expires_at}`, or `{two_factor: true, challenge}`. Send the challenge back in `X-Auth-Challenge` to `POST /api/auth/two-factor-challenge`, which answers `{token, expires_at, device_token}`. Send `Authorization: Bearer <token>` afterwards, and the device token in `X-Two-Factor-Device` at the next login to skip the challenge.

**Step-up:** a logged-in user who gets 403 `two_factor_required` posts a code to the challenge route with their session or bearer token; token mode answers `{device_token}`.

## Testing

- A user who must set up 2FA gets 403 `two_factor_setup_required` on almost every route. For tests not about 2FA, give the user confirmed 2FA and trust the session with `DeviceTrust::trustSession()`, or set `authentication.two_factor.required` to `false`.
- Assert audit entries through `AuditLog` (`event`, actor, subject, `channel`, `auth_methods`), or swap `audit.actions.record_audit_log` for a recording fake.

## Gotchas

- `Fortify::authenticateUsing` runs twice per login for users without 2FA; `AuthenticateCredentials` memoizes, so never consume codes or count attempts outside it.
- Sessions are only listed with the `database` session driver. Revoking a cookie session cycles the remember token.
- Device trust for `'session'` lives in the cookie session in session mode and never shows in `GET /api/user/devices`.
- The audit actor is the request's authenticated user, else the event's user for their own actions, else null (system actions).
- After a challenge, `login.succeeded` shows only `["totp"]` (or `["recovery_code"]`); the first factor is on the earlier `two_factor.challenged` entry.
- Bulk query updates and deletes bypass the audit log's immutability guard. `request_id` is only set for HTTP requests.

## Key Endpoints

Prefix `/api` by default (`routes.prefix`).

| Feature | Method | Endpoint |
|---|---|---|
| Login | POST | `/auth/login` |
| Logout | POST | `/auth/logout` |
| Request login code | POST | `/auth/login-code` |
| 2FA challenge (and step-up) | POST | `/auth/two-factor-challenge` |
| Password reset request | POST | `/auth/forgot-password` |
| Password reset | POST | `/auth/reset-password` |
| Current user | GET | `/user` |
| Update password | PUT | `/user/password` |
| Confirm identity | POST | `/user/confirm-password` |
| Confirmation status | GET | `/user/confirmed-password-status` |
| Request confirmation code | POST | `/user/confirm-password/code` |
| Enable / disable 2FA | POST / DELETE | `/user/two-factor-authentication` |
| Confirm 2FA | POST | `/user/confirmed-two-factor-authentication` |
| QR code / secret key | GET | `/user/two-factor-qr-code`, `/user/two-factor-secret-key` |
| Recovery codes | GET / POST | `/user/two-factor-recovery-codes` |
| Trusted devices | GET | `/user/devices` |
| Revoke device | DELETE | `/user/devices/{trustedDevice}` |
| Sessions and tokens | GET | `/user/sessions` |
| Revoke one / all others | DELETE | `/user/sessions/{session}`, `/user/sessions` |
