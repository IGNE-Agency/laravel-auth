<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

/**
 * The factors a user proved during a request. They are recorded in the
 * "authentication.methods" request attribute.
 */
enum AuthenticationMethod: string
{
    case Password = 'password';
    case LoginCode = 'login_code';
    case Totp = 'totp';
    case RecoveryCode = 'recovery_code';
}
