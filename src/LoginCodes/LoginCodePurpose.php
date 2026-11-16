<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes;

/**
 * What an emailed code is for. A code only works for the purpose it was made
 * for, so a login code can't confirm an identity and the reverse.
 */
enum LoginCodePurpose: string
{
    /**
     * Logging in, as the "password" on the login route.
     */
    case Login = 'login';

    /**
     * Confirming the identity of a logged-in user, as the "password" on the
     * confirm-password route.
     */
    case Confirm = 'confirm';
}
