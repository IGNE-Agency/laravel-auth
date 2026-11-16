<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The default mail that tells a user their two-factor authentication was
 * reset. Apps send their own by setting
 * "authentication.two_factor.reset_notification" to a notification with the
 * same constructor.
 */
class TwoFactorAuthenticationResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Authenticatable|null  $resetBy  the user who reset it, or null when it was reset from the console
     */
    public function __construct(
        public readonly ?Authenticatable $resetBy,
        public readonly CarbonInterface $resetAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resetByName = data_get($this->resetBy, 'name');
        $date = $this->resetAt->toDayDateTimeString();

        return (new MailMessage)
            ->subject(__('authentication::messages.two_factor_reset_mail.subject'))
            ->line(is_string($resetByName)
                ? __('authentication::messages.two_factor_reset_mail.reset_by', ['name' => $resetByName, 'date' => $date])
                : __('authentication::messages.two_factor_reset_mail.reset', ['date' => $date]))
            ->line(__('authentication::messages.two_factor_reset_mail.next'))
            ->line(__('authentication::messages.two_factor_reset_mail.unexpected'));
    }
}
