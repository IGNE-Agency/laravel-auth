<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Exceptions;

use Igne\Auth\Http\ErrorCode;

/**
 * The user tried to revoke the session or access token the request is sent
 * with. Logging out ends it.
 */
final class CurrentSessionException extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct(__('authentication::messages.current_session'));
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CurrentSession;
    }
}
