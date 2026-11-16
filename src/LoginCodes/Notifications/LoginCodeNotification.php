<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Notifications;

use Carbon\CarbonInterface;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * The default mail with an emailed code. Apps send their own by setting
 * "authentication.login_codes.notification" to a notification with the same
 * constructor.
 */
class LoginCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $url  the link to the frontend's login page, or null to send the code only
     */
    public function __construct(
        #[SensitiveParameter] public readonly string $code,
        public readonly CarbonInterface $expiresAt,
        public readonly ?string $url = null,
        public readonly LoginCodePurpose $purpose = LoginCodePurpose::Login,
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
        $prefix = $this->purpose === LoginCodePurpose::Confirm ? 'confirmation_code' : 'login_code';

        $mail = (new MailMessage)
            ->subject(__("authentication::messages.{$prefix}_mail.subject"))
            ->line(__("authentication::messages.{$prefix}_mail.introduction"))
            ->line(__('authentication::messages.code_mail.code', ['code' => $this->code]));

        if ($this->url !== null) {
            $mail->action(__('authentication::messages.code_mail.action'), $this->url);
        }

        return $mail
            ->line(__('authentication::messages.code_mail.expiry', ['minutes' => $this->minutesLeft()]))
            ->line(__('authentication::messages.code_mail.ignore'));
    }

    /**
     * Get the minutes until the code expires, counted when the mail is sent.
     */
    protected function minutesLeft(): int
    {
        return max(0, (int) ceil(Date::now()->diffInMinutes($this->expiresAt)));
    }
}
