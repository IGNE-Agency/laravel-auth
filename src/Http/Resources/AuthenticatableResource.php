<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Resources;

use Igne\Auth\Authentication\ConfirmIdentity;
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
     * The "confirmation_method" is the factor the user confirms their identity
     * with on the confirm-password route ("password" or "login_code"), or null
     * when they can't.
     *
     * @return array{id: mixed, email: mixed, confirmation_method: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAuthIdentifier(),
            'email' => data_get($this->resource, Fortify::email()),
            'confirmation_method' => app(ConfirmIdentity::class)->methodFor($this->resource)?->value,
        ];
    }
}
