<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * The default implementation of the TwoFactorAuthenticatable contract. It
 * includes Fortify's TwoFactorAuthenticatable trait, which handles the TOTP
 * secret and the recovery codes.
 */
trait InteractsWithTwoFactorAuthentication
{
    use TwoFactorAuthenticatable;

    /**
     * Determine whether the user must set up two-factor authentication before
     * they can use the app: "authentication.two_factor.required" by default.
     * Override it to require it from some users only.
     */
    public function requiresTwoFactorAuthentication(): bool
    {
        return (bool) config('authentication.two_factor.required', true);
    }

    /**
     * Get the devices the user verified two-factor authentication on, as the
     * model configured in "authentication.two_factor.model".
     *
     * @return MorphMany<TrustedDevice, $this>
     */
    public function trustedDevices(): MorphMany
    {
        /** @var class-string<TrustedDevice> $model */
        $model = config('authentication.two_factor.model', TrustedDevice::class);

        return $this->morphMany($model, 'authenticatable');
    }
}
