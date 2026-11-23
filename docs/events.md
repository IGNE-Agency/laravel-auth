# Events

Every security moment fires an event the app can listen to: to send a mail of its own, sync to another system, alert on suspicious activity, or update its own data. The package fires its own events for what Laravel and Fortify don't cover, and relies on theirs for the rest. The [audit log](audit-log.md) is written from these same events.

## The package's events

All live in `Igne\Auth\Events`, are final and carry read-only public properties.

| Event | Fired when | Properties |
|---|---|---|
| `LoginCodeRequested` | An emailed code was created and sent. Not fired when nothing was sent, such as for an unknown address or a rate-limited request. | `user`, `purpose` (`LoginCodePurpose::Login` or `::Confirm`) |
| `AccessTokenIssued` | A token client logged in and got an access token. | `user`, `tokenId`, `name`, `expiresAt` (null when tokens don't expire) |
| `AccessTokenRevoked` | An access token was revoked. | `user`, `tokenId`, `reason` |
| `SessionRevoked` | A user revoked one of their cookie sessions on the sessions endpoint. | `user`, `sessionHash` (SHA-256 of the session id) |
| `DeviceTrusted` | A user passed the two-factor challenge or confirmed their setup, so their device is trusted. | `user`, `deviceId` (null when only the cookie session is trusted), `remembered`, `expiresAt` |
| `DeviceTrustRevoked` | A trusted device was revoked. | `user`, `deviceId`, `reason` |
| `TwoFactorAuthenticationReset` | The two-factor authentication of a user was reset from the console or the Nova action. | `user`, `resetBy` (null from the console), `resetAt` |

The `reason` tells what caused a revocation:

| Reason | `AccessTokenRevoked` | `DeviceTrustRevoked` |
|---|---|---|
| `logout` | The token client logged out. | |
| `revoked` | The user revoked it on the sessions endpoint. | The user revoked it on the devices endpoint. |
| `two_factor_reset` | The user's two-factor authentication was reset. | The same. |
| `two_factor_disabled` | | The user disabled two-factor authentication. |
| `password_updated` | | The user changed their password. |
| `password_reset` | | The user reset their password. |

Trust that ends with its session or access token (when that is logged out, revoked or expires) fires no `DeviceTrustRevoked`; listen to the token or session event instead. Credentials never appear in an event: sessions are identified by a hash, and a login code event doesn't hold the code.

## Laravel's and Fortify's events

The package's routes run Laravel's and Fortify's own code, so their events fire as usual:

| Event | Fired when |
|---|---|
| `Illuminate\Auth\Events\Login` | A user logged in, in both modes, also after the two-factor challenge and from a "remember me" cookie. |
| `Illuminate\Auth\Events\Failed` | A login attempt failed. |
| `Illuminate\Auth\Events\Lockout` | Login was throttled. |
| `Illuminate\Auth\Events\Logout` | A user logged out. For a token client it carries no user; listen to `AccessTokenRevoked` with reason `logout` for those. |
| `Illuminate\Auth\Events\PasswordResetLinkSent` | A password reset mail was sent. |
| `Illuminate\Auth\Events\PasswordReset` | A user reset their password. |
| `Laravel\Fortify\Events\PasswordUpdatedViaController` | A logged-in user changed their password. |
| `Laravel\Fortify\Events\TwoFactorAuthenticationEnabled`, `TwoFactorAuthenticationConfirmed`, `TwoFactorAuthenticationDisabled` | A user started, finished or removed their two-factor setup. |
| `Laravel\Fortify\Events\TwoFactorAuthenticationChallenged`, `ValidTwoFactorAuthenticationCodeProvided`, `TwoFactorAuthenticationFailed` | A login was sent to the challenge, and the challenge passed or failed. |
| `Laravel\Fortify\Events\RecoveryCodeReplaced`, `RecoveryCodesGenerated` | A user passed the challenge with a recovery code, or got new recovery codes. |

## Listening

Write a listener in `app/Listeners`; Laravel discovers it from the type of its `handle()` argument. Without discovery, register it in a service provider's `boot()` with `Event::listen(DeviceTrusted::class, NotifyNewTrustedDevice::class)`.

This one mails a user when a device is remembered for longer than the session:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\NewTrustedDeviceNotification;
use Igne\Auth\Events\DeviceTrusted;

final class NotifyNewTrustedDevice
{
    public function handle(DeviceTrusted $event): void
    {
        if (! $event->remembered || ! method_exists($event->user, 'notify')) {
            return;
        }

        $event->user->notify(new NewTrustedDeviceNotification($event->expiresAt));
    }
}
```

[Mails](mails.md) shows the notification side.

Keep in mind:

- **Listeners run inside the request.** An exception in a synchronous listener fails the login, logout or revocation that fired it. Put mails, external syncs and other slow or fallible work in a queued notification or job, or make the listener queued (`ShouldQueue`).
- **Some events fire inside a transaction.** A two-factor reset revokes the devices and access tokens in one transaction, so their events fire before it commits. Queued listeners for `DeviceTrustRevoked` and `AccessTokenRevoked` should implement `ShouldQueueAfterCommit`, or they may run before the changes are saved, or for changes that were rolled back.
- **The user is the app's model.** `user` is typed `Authenticatable`; check it with `instanceof` against the app's `User` before using app-specific methods.
- **Don't log personal data.** The events carry the user; log the `getAuthIdentifier()`, never the email address or name.
- **Don't replace the package's listeners.** The package listens to the events above itself, to trust and revoke devices, send the two-factor reset mail and write the audit log. Add listeners next to them; to change what they do, use the config: `two_factor.reset_notification` for the mail, `audit.enabled` or `audit.actions.record_audit_log` for the audit log.

## Reacting to every security event in one place

To send every security event to one place, such as a SIEM or a Slack channel, extend `Igne\Auth\Audit\Actions\RecordAuditLogAction` instead of listening to each event; see [Audit log](audit-log.md). It gets every event as one typed `AuditEntry`, with the actor, the channel, the IP address and the request id already filled in.
