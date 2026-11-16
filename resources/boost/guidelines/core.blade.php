# IGNE Authentication

- This app authenticates through `igne-agency/laravel-auth`, a headless package on Laravel Fortify and Sanctum. Its JSON routes run Fortify's own controllers (or thin subclasses that validate with a FormRequest) for SPA cookie clients (session mode) and bearer-token clients (token mode).
- IMPORTANT: Activate `authentication-development` when working on login, login codes, two-factor authentication, password confirmation, access tokens, trusted devices, sessions or the audit log.
- The concepts, naming and layers below are the shared vocabulary for authentication and authorization code, also for parts the package does not build yet.

## Concepts

| Concept | Meaning |
|---|---|
| Authentication | Who are you? |
| Authorization | What are you allowed to do? |
| Identity | The account, person or service that is authenticated. |
| Provider | The table, model or source that can log in. |
| Space | The area or context where access applies. |
| Role | A named bundle of permissions within a space. |
| Permission | A specific capability. |
| Policy / Gate / Authorization Handler | Code that decides whether an action is allowed. |
| Scope | A token, session or API limitation. |
| Audit Log | Record of security-relevant actions. |

- Built by the package: identity and its authenticators (password and emailed login codes, declared with contracts), per-device TOTP two-factor authentication, header sessions for token clients, access token lifetimes, self-service devices and sessions, the audit log and the OpenAPI documentation.
- Concepts only, for later: Space, Role, Permission, Scope, SSO and external identities, and Passport as the token issuer. Don't model them ad hoc inside auth code; when a feature needs one, use these names.

## Naming

| Concept | Recommended name | Avoid |
|---|---|---|
| Person or account | `User`, `Account`, `Identity` | `LoginUser`, `AuthUser` |
| Type of authenticatable model | `Provider`, `IdentityProvider`, `AuthenticatableType` | `GuardUserType` |
| Application area | `Space`, `Portal`, `Context` | `PlatformType`, `AppType` |
| Named permission group | `Role` | `UserType`, `Level`, `Rank` |
| Specific action permission | `Permission` | `Right`, `Access`, `Ability` |
| Business access rule | `Policy`, `AuthorizationHandler` | `PermissionCheckerService` |
| API token limitation | `Scope` | `Permission`, unless it is actually a permission |
| Event history | `AuditLog`, `SecurityEvent` | `Log`, `History` |

Only create a separate provider (`Admin`, `Customer`, `Client`, `Employee`, `User`) when a person needs a separate account with a clear meaning; otherwise use the default `User`.

## Authorization layers

A request passes these layers in order. Put each check in its own layer.

| # | Layer | Question | In this app |
|---|---|---|---|
| 1 | Authentication Middleware | Is the user logged in? | `auth:sanctum`, then the package's `EnsureTwoFactorAuthenticated` |
| 2 | Space Middleware | Which portal or context is this request for? | later |
| 3 | Guard Permission | Can this identity access this space? | later |
| 4 | Route Authorization | Can the user perform this general action? | `can:` middleware, Gate |
| 5 | Policy / Handler | Can the user perform this action on this specific model? | Policy |
| 6 | Query Scope | Only return records the user may see. | Eloquent scope |
| 7 | Audit Log | Record important access and security actions. | package events, `audit_logs` |

## Package rules

- Declare how an account logs in with contracts on the authenticatable model: `Igne\Auth\Authentication\Contracts\PasswordAuthenticatable` (trait `AuthenticatesWithPassword`), `Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable` (trait `AuthenticatesWithLoginCode`) and `Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable` (trait `InteractsWithTwoFactorAuthentication`, which includes Fortify's `TwoFactorAuthenticatable`). Narrow a method by overriding `canAuthenticateWithPassword()`, `canAuthenticateWithLoginCode()` or `requiresTwoFactorAuthentication()`, not with a column or an enum.
- Name package code after Laravel's `Authenticatable`, not `User`: not every app authenticates a `User`. Laravel's and Fortify's own names stay (`UserProvider`, `$request->user()`, `ResetsUserPasswords`, the `/user/...` routes).
- Validate the input of a package route with a FormRequest. A Fortify route gets one through a thin subclass of Fortify's controller, whose `__invoke()` takes the FormRequest and calls Fortify's method; the Fortify action then only acts on the validated input.
- Never add a login path that bypasses `Fortify::authenticateUsing` (the package's `AuthenticateCredentials`): no custom login controllers, `Auth::login()` shortcuts or token endpoints. They would skip the throttle, the two-factor challenge and the audit log.
- Put protected routes in the `web` or `api` middleware group, so the two-factor enforcement applies. Exempt a route only deliberately: `TwoFactorEnforcement::except()` or `exceptSetup()`, `authentication.two_factor.except` or `except_setup` in the config, or `->withoutMiddleware(EnsureTwoFactorAuthenticated::class)`.
- Send everything users receive through the notifications configured in `config/authentication.php` (`login_codes.notification`, `two_factor.reset_notification`, `passwords.reset_notification`), so each project can override them. User-facing strings live in the `authentication::messages` translations.
- Record security-relevant actions through the package events (`Igne\Auth\Events\*`) and Laravel's and Fortify's auth events, which the audit log subscribes to. Never write, update or delete `audit_logs` rows directly.
- Keep the package headless: no views, Blade templates or screens. Frontends and admin panels such as Nova are adapters.
- Change behaviour through the config (`tokens.issuer`, `login_codes.actions`, `audit.actions.record_audit_log`, `auth_resource`, the models), not by editing the package.
- Package errors answer `{message, code}` with a code from `Igne\Auth\Http\ErrorCode`. Fortify's errors stay Laravel's own (401, 422, 423, 429).

## Operational notes

- `GET user/sessions` lists cookie sessions only with the `database` session driver; access tokens are always listed.
- Device trust for `'session'` lives in the cookie session in session mode, so it never appears in `GET user/devices`.
- Revoking a cookie session cycles the user's remember token.
- The audit actor is the request's authenticated user, else the event's user for actions they take themselves (such as logging in), else null for system actions (such as a reset from the console).
- After a two-factor challenge, `login.succeeded` has `auth_methods` `["totp"]` (or `["recovery_code"]`); the first factor is on the earlier `two_factor.challenged` entry.
- The audit log's immutability guard runs on model events, so bulk query updates and deletes bypass it.
- `request_id`, `channel`, `auth_methods`, the IP address and the user agent are only set for HTTP requests.
