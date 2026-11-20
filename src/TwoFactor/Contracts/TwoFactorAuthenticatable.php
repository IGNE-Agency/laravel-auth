<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Contracts;

use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A user that can have TOTP two-factor authentication. The package's
 * InteractsWithTwoFactorAuthentication trait implements it, together with
 * Fortify's TwoFactorAuthenticatable trait that it includes.
 */
interface TwoFactorAuthenticatable extends Authenticatable
{
    /**
     * Determine whether the user must set up two-factor authentication before
     * they can use the app.
     */
    public function requiresTwoFactorAuthentication(): bool;

    /**
     * Determine whether the user still has to set up two-factor
     * authentication: Fortify's two-factor feature is on, the user requires
     * it and hasn't enabled it yet.
     */
    public function mustSetUpTwoFactorAuthentication(): bool;

    /**
     * Determine whether the user has two-factor authentication enabled (and
     * confirmed, when Fortify confirms it). Fortify's TwoFactorAuthenticatable
     * trait implements it.
     *
     * @return bool
     */
    public function hasEnabledTwoFactorAuthentication();

    /**
     * Get the devices the user verified two-factor authentication on.
     *
     * @return MorphMany<TrustedDevice, covariant Model>
     */
    public function trustedDevices(): MorphMany;
}
