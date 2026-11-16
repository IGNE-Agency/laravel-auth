# Concepts

The words IGNE projects use for authentication and authorization, what this package builds of them, and where each check belongs. The same text ships as a Laravel Boost guideline (`resources/boost/guidelines/core.blade.php`), so AI agents in the app follow it too; see the README's [AI guidelines](../README.md#ai-guidelines).

## Glossary

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

## What the package builds

| Concept | Today | How |
|---|---|---|
| Identity and authenticators | Built | The authenticatable model declares its login methods with contracts: `PasswordAuthenticatable` for a password, `LoginCodeAuthenticatable` for emailed login codes. Both run through one credential check on `Fortify::authenticateUsing`. |
| Two-factor authentication | Built | `TwoFactorAuthenticatable` gets Fortify's TOTP, asked again per device, with a configurable trust lifetime and automatic enforcement. |
| Sessions and tokens | Built | SPA cookie sessions, access tokens with absolute and idle lifetimes, and header sessions that let token clients run Fortify's session-based flows. |
| Devices and sessions self-service | Built | Users list and revoke their trusted devices, cookie sessions and access tokens. |
| Audit Log | Built | An append-only `audit_logs` table, written from Laravel's, Fortify's and the package's events. |
| OpenAPI | Built | Explicit schemas for every package route in the app's Scramble document. The package's own tests snapshot them and validate every response against them. |
| Provider | Default only | The package reads the user model from the guard's provider. Every project starts with one provider: `User`. |
| Space, Role, Permission | Later | Concepts only. |
| Scope | Later | Concept only. Access tokens get every Sanctum ability (`*`), and nothing checks them. |
| SSO and external identities | Later | Concept only. |
| Passport as token issuer | Later | Concept only. The token issuer contract leaves room for it; see the README's [Passport](../README.md#passport) section. |

When a feature needs a concept marked "later", use its name from this page instead of inventing a new one.

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

### Providers

Only create a separate provider (`Admin`, `Customer`, `Client`, `Employee`, `User`) when a person needs a separate account with a clear meaning. Otherwise use the default `User`, and tell users apart by what they may do, not by their model.

## Authorization layers

A request passes these layers in order. Each check belongs in its own layer, so none is skipped and none is repeated.

```text
Authentication Middleware   Is the user logged in?
        ↓
Space Middleware            Which portal or context is this request for?
        ↓
Guard Permission            Can this identity access this space?
        ↓
Route Authorization         Can the user perform this general action?
        ↓
Policy / Handler            Can the user perform this action on this specific model?
        ↓
Query Scope                 Only return records the user may see.
        ↓
Audit Log                   Record important access and security actions.
```

In a project on this package:

1. **Authentication:** `auth:sanctum` authenticates the cookie session or the access token. The package's `EnsureTwoFactorAuthenticated` runs right after it, in every route of the `web` and `api` groups.
2. **Space** and **guard permission:** not built yet.
3. **Route authorization:** the `can:` middleware or a Gate check.
4. **Policy / handler:** a Laravel policy for the model.
5. **Query scope:** an Eloquent scope on list queries.
6. **Audit log:** the package records its own security events. See the README's [audit log](../README.md#audit-log) section.

## Package rules

- **Declare login methods with the contracts** on the authenticatable model. Narrow them by overriding `canAuthenticateWithPassword()`, `canAuthenticateWithLoginCode()` or `requiresTwoFactorAuthentication()`, not with a column or an enum: a login method is a capability, not data.
- **One login path.** Every login runs through `Fortify::authenticateUsing`. A second path would skip the throttle, the two-factor challenge and the audit log.
- **Protected routes live in the `web` or `api` group**, so the two-factor enforcement applies. A route skips it only deliberately: through `TwoFactorEnforcement`, the `two_factor.except` and `two_factor.except_setup` config, or `->withoutMiddleware()`.
- **Everything sent to users goes through notifications** that the config names, so every project can override them.
- **Security-relevant actions are recorded** through the package's events and the audit log.
- **Headless.** The package ships no views. Screens belong to the frontends; admin panels such as Nova are adapters.
