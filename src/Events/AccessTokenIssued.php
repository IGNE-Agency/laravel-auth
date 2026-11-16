<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An access token was issued to a user.
 */
final class AccessTokenIssued
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $tokenId,
        public readonly string $name,
        public readonly ?CarbonInterface $expiresAt,
    ) {}
}
