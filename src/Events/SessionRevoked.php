<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A web session of a user was revoked. The session is identified by the
 * SHA-256 hash of its id, never by the id itself.
 */
final class SessionRevoked
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $sessionHash,
    ) {}
}
