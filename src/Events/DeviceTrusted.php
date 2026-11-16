<?php

declare(strict_types=1);

namespace Igne\Auth\Events;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A user verified two-factor authentication, so the device they used is
 * trusted. The device id is null when only the session is trusted; otherwise
 * it is the trusted device row, remembered when the user asked for it.
 */
final class DeviceTrusted
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly ?string $deviceId,
        public readonly bool $remembered,
        public readonly ?CarbonInterface $expiresAt,
    ) {}
}
