<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Exceptions;

use Igne\Auth\Http\Exceptions\AuthenticationException;

/**
 * The user must set up or pass two-factor authentication before the request
 * can continue. EnsureTwoFactorAuthenticated throws it for an API request,
 * and hands it to the responder registered for the request otherwise.
 */
abstract class MissingTwoFactorAuthenticationException extends AuthenticationException {}
