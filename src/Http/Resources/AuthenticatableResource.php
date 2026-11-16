<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Resources;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Fortify\Fortify;

/**
 * The authenticated user, as GET /api/user returns it. Extend it and set
 * "authentication.auth_resource" to add project fields.
 *
 * @property Authenticatable $resource
 */
class AuthenticatableResource extends JsonResource
{
    /**
     * The resource is returned without a "data" wrapper.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{id: mixed, email: mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAuthIdentifier(),
            'email' => data_get($this->resource, Fortify::email()),
        ];
    }
}
