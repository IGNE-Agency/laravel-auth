# Mails

The package sends three mails. Each is a Laravel notification named in `config/authentication.php`, so the app can change its text, replace it, or turn it off without touching the package.

| Mail | Default notification | Config key | Sent when | Queued |
|---|---|---|---|---|
| Login code and confirmation code | `Igne\Auth\LoginCodes\Notifications\LoginCodeNotification` | `login_codes.notification` | A user requests a code on `POST auth/login-code` (purpose `login`) or `POST user/confirm-password/code` (purpose `confirm`). | Yes |
| Password reset | `Igne\Auth\Authentication\Notifications\ResetPasswordNotification` | `passwords.reset_notification` | A user requests a reset link on Fortify's forgot-password route. | No (extends Laravel's `ResetPassword`) |
| Two-factor authentication reset | `Igne\Auth\TwoFactor\Notifications\TwoFactorAuthenticationResetNotification` | `two_factor.reset_notification` | The two-factor authentication of a user is reset with `authentication:two-factor:reset` or the Nova action. | Yes |

The package never mails an address it doesn't know: a code request or reset request for an unknown address sends nothing and answers the same as a known one.

## The links in the mails

The login code mail and the password reset mail link to a page of the frontend, not to a package route:

- `login_codes.link_url` (`AUTH_LOGIN_CODE_URL`), with the `{email}` and `{code}` placeholders filled in. Without it, the mail holds the code only. Confirmation codes never get a link. The page posts the code to the login route; following the link doesn't log in by itself.
- `passwords.reset_url` (`AUTH_PASSWORD_RESET_URL`), with the `{token}` and `{email}` placeholders filled in. Without it, the mail falls back to Laravel's `password.reset` route. Callbacks registered with `ResetPassword::createUrlUsing()` or `ResetPassword::toMailUsing()` win over both, which keeps Nova's own reset mail working.

```dotenv
AUTH_LOGIN_CODE_URL="https://app.example.com/login/code?email={email}&code={code}"
AUTH_PASSWORD_RESET_URL="https://app.example.com/reset-password?token={token}&email={email}"
```

## Changing the text

The login code and two-factor reset mails take their text from the `authentication::messages` translations (`login_code_mail`, `confirmation_code_mail`, `code_mail` and `two_factor_reset_mail`), in English and Dutch. Publish them and edit the copy in `lang/vendor/authentication/{locale}/messages.php`:

```bash
php artisan vendor:publish --tag=authentication-lang
```

The password reset mail uses Laravel's own strings (`Reset Password Notification`, `Reset Password`, …); translate them in the app's `lang/{locale}.json`.

Mails go out in the user's language when the model implements Laravel's `HasLocalePreference`. The layout is Laravel's mail template; `php artisan vendor:publish --tag=laravel-mail` publishes it for the app's branding.

## Replacing a mail

Set the config key to a notification of your own. The package constructs it itself, so it must take the same constructor arguments as the default:

| Config key | Constructor |
|---|---|
| `login_codes.notification` | `(string $code, CarbonInterface $expiresAt, ?string $url, LoginCodePurpose $purpose)` |
| `passwords.reset_notification` | `(string $token)` |
| `two_factor.reset_notification` | `(?Authenticatable $resetBy, CarbonInterface $resetAt)` |

The defaults aren't final, so extending one and overriding `toMail()` (or `via()`, to send over another channel too) keeps the constructor right:

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Illuminate\Notifications\Messages\MailMessage;

final class LoginCodeMail extends LoginCodeNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->purpose === LoginCodePurpose::Confirm ? 'mail.confirmation_code.subject' : 'mail.login_code.subject'))
            ->markdown('mail.login-code', [
                'code' => $this->code,
                'url' => $this->url,
                'minutes' => $this->minutesLeft(),
            ]);
    }
}
```

```php
// config/authentication.php
'login_codes' => [
    // ...
    'notification' => App\Notifications\LoginCodeMail::class,
],
```

A class that isn't a notification throws a `LogicException` when the mail is sent. The code is a secret: don't log it, and keep it out of anything but the mail.

Keep your mails queued (`ShouldQueue`), and add it when you replace the password reset mail, which Laravel doesn't queue. Both request routes answer the same for every address; a mail sent inline makes a known address slower to answer than an unknown one, and that gives away which accounts exist.

## Turning a mail off

Set `two_factor.reset_notification` to `null` to send no mail after a two-factor reset. The login code and password reset mails can't be turned off, since their flows don't work without them; switch the feature off instead (drop `LoginCodeAuthenticatable` from the model, or Fortify's `resetPasswords` feature).

## Adding mails of your own

For a mail the package doesn't send, such as "a new device was trusted" or "your password was changed", listen to the event of that moment and send a notification from the listener. [Events](events.md) lists every event and what it carries.

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\PasswordChangedNotification;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

final class SendPasswordChangedNotification
{
    public function handle(PasswordUpdatedViaController $event): void
    {
        $event->user->notify(new PasswordChangedNotification);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.password_changed.subject'))
            ->line(__('mail.password_changed.introduction'))
            ->line(__('mail.password_changed.unexpected'));
    }
}
```

Laravel discovers the listener in `app/Listeners` from the type of its `handle()` argument. Queue the notification rather than the listener when the listener does nothing else, so a slow mail server never slows down the request.
