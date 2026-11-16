<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;

/**
 * The default password reset mail. It links to the frontend page in
 * "authentication.passwords.reset_url". Laravel's ResetPassword::createUrlUsing()
 * and ResetPassword::toMailUsing() callbacks still take precedence, so Nova's
 * own reset mail keeps working.
 */
class ResetPasswordNotification extends ResetPassword
{
    /**
     * Get the reset URL for the given notifiable.
     *
     * @param  CanResetPassword  $notifiable
     * @return string
     */
    protected function resetUrl($notifiable)
    {
        $url = config('authentication.passwords.reset_url');

        if (static::$createUrlCallback || ! is_string($url) || $url === '') {
            return parent::resetUrl($notifiable);
        }

        return strtr($url, [
            '{token}' => rawurlencode($this->token),
            '{email}' => rawurlencode($notifiable->getEmailForPasswordReset()),
        ]);
    }
}
