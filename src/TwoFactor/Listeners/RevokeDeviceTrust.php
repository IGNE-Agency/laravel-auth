<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Listeners;

use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/**
 * Revokes a user's trusted devices when their security changes. When the user
 * disables two-factor authentication or changes their password themselves,
 * the device they did it on stays trusted; a password reset revokes all.
 */
final class RevokeDeviceTrust
{
    public function __construct(
        private readonly Container $container,
        private readonly DeviceTrust $deviceTrust,
    ) {}

    public function handle(TwoFactorAuthenticationDisabled|PasswordUpdatedViaController|PasswordReset $event): void
    {
        if ($event instanceof PasswordReset) {
            $this->deviceTrust->revokeAll($event->user, 'password_reset');

            return;
        }

        if (! $event->user instanceof Authenticatable) {
            return;
        }

        $this->deviceTrust->revokeAll(
            $event->user,
            $event instanceof TwoFactorAuthenticationDisabled ? 'two_factor_disabled' : 'password_updated',
            $this->container->make(Request::class),
        );
    }
}
