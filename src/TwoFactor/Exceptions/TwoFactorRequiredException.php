<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Exceptions;

/**
 * The user has two-factor authentication, but did not verify it on this
 * device (or its trust lapsed). They pass the two-factor challenge to
 * continue.
 */
final class TwoFactorRequiredException extends MissingTwoFactorAuthenticationException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.two_factor_required'));
    }
}
