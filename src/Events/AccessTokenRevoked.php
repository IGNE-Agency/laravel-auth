<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An access token of a user was revoked. The reason is "logout" for a logout
 * and "revoked" by default otherwise.
 */
final class AccessTokenRevoked
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $tokenId,
        public readonly string $reason,
    ) {}
}
