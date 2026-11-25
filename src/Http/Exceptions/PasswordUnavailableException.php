<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Exceptions;

/**
 * The authenticated user can't use a password, so a password route is
 * unavailable to them.
 */
final class PasswordUnavailableException extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.password_unavailable'));
    }
}
