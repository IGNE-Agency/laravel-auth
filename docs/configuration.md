# Configuration

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

Everything the package sends to users is a notification named in the config (`login_codes.notification`, `two_factor.reset_notification`, `passwords.reset_notification`). Swap one for a notification of your own with the same constructor. The strings are in the `authentication::messages` translations (publish tag `authentication-lang`). See [Mails](mails.md).
