<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions\Http\Resources;

use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trusted device of the authenticated user, as GET /api/user/devices lists
 * it. "is_current" marks the device the request comes from.
 *
 * @property TrustedDevice $resource
 */
final class TrustedDeviceResource extends JsonResource
{
    public function __construct(TrustedDevice $resource, private readonly bool $isCurrent = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: string, name: string, ip_address: string|null, remembered: bool, last_used_at: string|null, expires_at: string|null, is_current: bool}
     */
    public function toArray(Request $request): array
    {
        $device = $this->resource;

        return [
            'id' => $device->id,
            'name' => $device->name,
            'ip_address' => $device->ip_address,
            'remembered' => $device->remembered,
            'last_used_at' => $device->last_used_at?->toJSON(),
            'expires_at' => $device->expires_at?->toJSON(),
            'is_current' => $this->isCurrent,
        ];
    }
}
