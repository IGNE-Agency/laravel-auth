<?php

declare(strict_types=1);

namespace Igne\Auth\Http;

/**
 * The stable "code" values of the package's error responses.
 */
enum ErrorCode: string
{
    case PasswordUnavailable = 'password_unavailable';
}
