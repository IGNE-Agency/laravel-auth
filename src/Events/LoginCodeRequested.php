<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An emailed code was created and sent to a user. It is not fired for
 * requests that send nothing, such as one for an unknown email address.
 */
final class LoginCodeRequested
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly LoginCodePurpose $purpose,
    ) {}
}
