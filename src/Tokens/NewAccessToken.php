<?php

declare(strict_types=1);

namespace Igne\Auth\Tokens;

use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * A freshly issued access token. The plain-text token is only known here.
 */
final readonly class NewAccessToken
{
    public function __construct(
        #[SensitiveParameter] public string $plainTextToken,
        public string $id,
        public ?CarbonInterface $expiresAt,
    ) {}
}
