<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Listeners;

use Igne\Auth\Events\TwoFactorAuthenticationReset;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * Tells the user their two-factor authentication was reset, with the
 * notification configured in "authentication.two_factor.reset_notification"
 * (null sends nothing). It is constructed as (?Authenticatable $resetBy,
 * CarbonInterface $resetAt).
 */
final class SendTwoFactorAuthenticationResetNotification
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @throws LogicException
     */
    public function handle(TwoFactorAuthenticationReset $event): void
    {
        /** @var class-string|null $class */
        $class = $this->config->get('authentication.two_factor.reset_notification');

        if ($class === null || ! method_exists($event->user, 'notify')) {
            return;
        }

        $notification = new $class($event->resetBy, $event->resetAt);

        if (! $notification instanceof Notification) {
            throw new LogicException("The two-factor reset notification [{$class}] must be a notification.");
        }

        $event->user->notify($notification);
    }
}
