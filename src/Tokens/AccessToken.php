<?php

declare(strict_types=1);

namespace Igne\Auth\Tokens;

use Carbon\CarbonInterface;

/**
 * An access token of a user, without its secret.
 */
final readonly class AccessToken
{
    public function __construct(
        public string $id,
        public string $name,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $lastUsedAt,
        public ?CarbonInterface $expiresAt,
    ) {}
}
