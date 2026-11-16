<?php

declare(strict_types=1);

namespace Igne\Auth\Audit;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One security event, with the context of the request it happened in, as the
 * record action receives it.
 */
final readonly class AuditEntry
{
    /**
     * @param  Authenticatable|null  $actor  who acted, when known
     * @param  Authenticatable|null  $subject  the account the event is about
     * @param  string|null  $channel  "session" or "token", for HTTP requests
     * @param  list<string>|null  $authMethods  the factors the user proved in the request
     * @param  string|null  $credentialHash  the SHA-256 hash of the session or access token id
     * @param  string|null  $requestId  the same for every entry of one request
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AuditEvent $event,
        public CarbonInterface $occurredAt,
        public ?Authenticatable $actor = null,
        public ?Authenticatable $subject = null,
        public ?string $guard = null,
        public ?string $channel = null,
        public ?array $authMethods = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $credentialHash = null,
        public ?string $requestId = null,
        public array $metadata = [],
    ) {}
}
