<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticatable;
use LogicException;

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
        return config()->boolean('authentication.two_factor.required', true);
    }

    /**
     * Determine whether the user still has to set up two-factor
     * authentication: Fortify's two-factor feature is on,
     * requiresTwoFactorAuthentication() requires it and the user hasn't
     * enabled it yet.
     */
    public function mustSetUpTwoFactorAuthentication(): bool
    {
        return Features::enabled(Features::twoFactorAuthentication())
            && $this->requiresTwoFactorAuthentication()
            && ! $this->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Get the devices the user verified two-factor authentication on, as the
     * model configured in "authentication.two_factor.model".
     *
     * @return MorphMany<TrustedDevice, $this>
     */
    public function trustedDevices(): MorphMany
    {
        $model = config()->string('authentication.two_factor.model', TrustedDevice::class);

        if (! is_a($model, TrustedDevice::class, true)) {
            throw new LogicException("The trusted device model [{$model}] must extend [".TrustedDevice::class.'].');
        }

        return $this->morphMany($model, 'authenticatable');
    }
}
