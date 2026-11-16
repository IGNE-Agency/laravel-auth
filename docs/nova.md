# Nova

With [Laravel Nova](https://nova.laravel.com) 5 installed, `Igne\Auth\Nova\NovaIntegration` lets Nova's own screens handle authentication:

- Nova's login, logout, challenge, password reset pages and assets skip the two-factor enforcement, and its User Security page skips the setup check;
- a Nova user without two-factor authentication goes to User Security, and one on an untrusted device is logged out of the session (which stays) and sent to Nova's challenge;
- a route named `password.confirm` redirects to Nova's password confirmation page, unless the app defines one.

Nova logs in through `Fortify::authenticateUsing`, so the credential check and the per-device challenge apply to it. In the app:

- enable the same `updatePasswords` and `twoFactorAuthentication` features in `NovaServiceProvider::fortify()`;
- set Fortify's `limiters.two-factor` to `authentication-two-factor`, so Nova's challenge uses the package limiter;
- add the action `Igne\Auth\Nova\Actions\ResetTwoFactorAuthentication` to the user resource, and define the `resetTwoFactorAuthentication` gate (the package's default denies everyone).

Nova's challenge has no "remember" checkbox, so Nova logins trust a device for `two_factor.device_trust.expires_in_days`.

Nova is paid, so the package only suggests it. The package's own CI covers the adapter in its `nova` job when the repository has the `COMPOSER_AUTH` secret: it installs Nova, runs Larastan with `phpstan-nova.neon.dist` (which includes `src/Nova`) and runs the tests in `tests/Nova` on a Testbench Nova setup (`tests/Fixtures/Nova`). Without the secret, such as on pull requests from forks, the job skips those steps. [Development](development.md#run-the-nova-checks-locally) shows how to run them locally.
