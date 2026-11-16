<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A trusted device of a user was revoked. The reason is what caused it, such
 * as "two_factor_disabled", "password_updated", "password_reset" or
 * "two_factor_reset".
 */
final class DeviceTrustRevoked
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $deviceId,
        public readonly string $reason,
    ) {}
}
