<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Listeners;

use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\RecoveryCodeReplaced;

/**
 * Marks the request in which the user logged in with a recovery code, so the
 * device is trusted for the session or access token only, never remembered.
 * Fortify fires RecoveryCodeReplaced before ValidTwoFactorAuthenticationCodeProvided.
 */
final class RememberRecoveryCodeUse
{
    public function __construct(private readonly Container $container) {}

    public function handle(RecoveryCodeReplaced $event): void
    {
        $this->container->make(Request::class)->attributes->set(DeviceTrust::RECOVERY_CODE_ATTRIBUTE, true);
    }
}
