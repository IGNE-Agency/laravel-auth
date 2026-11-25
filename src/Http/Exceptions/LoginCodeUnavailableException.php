<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Exceptions;

/**
 * The authenticated user confirms their identity with another factor than an
 * emailed code, so they can't request a confirmation code.
 */
final class LoginCodeUnavailableException extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.login_code_unavailable'));
    }
}
