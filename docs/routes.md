# Routes

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
