<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The default implementation of the PasswordAuthenticatable contract. The
 * model must be notifiable to receive the password reset notification.
 */
trait AuthenticatesWithPassword
{
    /**
     * Determine whether the user can log in with a password right now. A user
     * without a password can't.
     */
    public function canAuthenticateWithPassword(): bool
    {
        return filled($this->getAuthPassword());
    }

    /**
     * Send the password reset notification configured in
     * "authentication.passwords.reset_notification".
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        /** @var class-string<Notification> $notification */
        $notification = config('authentication.passwords.reset_notification');

        $this->notify(new $notification($token));
    }
}
