<?php

declare(strict_types=1);

namespace Igne\Auth\Http;

/**
 * The stable "code" values of the package's error responses.
 */
enum ErrorCode: string
{
    case PasswordUnavailable = 'password_unavailable';
    case LoginCodeUnavailable = 'login_code_unavailable';
    case TwoFactorSetupRequired = 'two_factor_setup_required';
    case TwoFactorRequired = 'two_factor_required';
    case CurrentSession = 'current_session';
}
