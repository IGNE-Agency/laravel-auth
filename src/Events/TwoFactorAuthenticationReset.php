<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The two-factor authentication of a user was reset: it is disabled, and the
 * user is logged out everywhere. The user who reset it is null when it was
 * reset outside a request, such as from the console.
 */
final class TwoFactorAuthenticationReset
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly ?Authenticatable $resetBy,
        public readonly CarbonInterface $resetAt,
    ) {}
}
