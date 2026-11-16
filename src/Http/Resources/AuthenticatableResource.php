<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Resources;

use Igne\Auth\Authentication\ConfirmIdentity;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
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
     * with on the confirm-password route ("password", "totp" or "login_code"),
     * or null when they can't. "two_factor_enabled" tells whether the user set
     * up two-factor authentication, and "two_factor_required" whether they
     * must.
     *
     * @return array{id: mixed, email: mixed, confirmation_method: string|null, two_factor_enabled: bool, two_factor_required: bool}
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->getAuthIdentifier(),
            'email' => data_get($user, Fortify::email()),
            'confirmation_method' => app(ConfirmIdentity::class)->methodFor($user)?->value,
            'two_factor_enabled' => $user instanceof TwoFactorAuthenticatable && (bool) $user->hasEnabledTwoFactorAuthentication(),
            'two_factor_required' => $user instanceof TwoFactorAuthenticatable && $user->requiresTwoFactorAuthentication(),
        ];
    }
}
