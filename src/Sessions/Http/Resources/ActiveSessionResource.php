<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions\Http\Resources;

use Igne\Auth\Sessions\ActiveSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A web session or access token of the authenticated user, as
 * GET /api/user/sessions lists it.
 *
 * @property ActiveSession $resource
 */
final class ActiveSessionResource extends JsonResource
{
    /**
     * @return array{id: string, type: string, name: string, ip_address: string|null, last_active_at: string|null, is_current: bool}
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            'id' => $session->id,
            'type' => $session->type->value,
            'name' => $session->name,
            'ip_address' => $session->ipAddress,
            'last_active_at' => $session->lastActiveAt?->toJSON(),
            'is_current' => $session->isCurrent,
        ];
    }
}
