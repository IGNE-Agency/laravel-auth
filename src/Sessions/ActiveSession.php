<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions;

use Carbon\CarbonInterface;
use Igne\Auth\Http\Channel;

/**
 * A place a user is logged in: a web session (session mode) or an access
 * token (token mode). Its id is "session:{sha256 of the session id}" or
 * "token:{access token id}", never a raw session id or token.
 */
final readonly class ActiveSession
{
    public function __construct(
        public string $id,
        public Channel $type,
        public string $name,
        public ?string $ipAddress,
        public ?CarbonInterface $lastActiveAt,
        public bool $isCurrent,
    ) {}
}
