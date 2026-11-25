<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Exceptions;

/**
 * The user must set up two-factor authentication before they can continue.
 */
final class TwoFactorSetupRequiredException extends MissingTwoFactorAuthenticationException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.two_factor_setup_required'));
    }
}
