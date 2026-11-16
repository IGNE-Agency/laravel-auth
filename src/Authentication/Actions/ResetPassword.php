<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Sets the new password of an account that completed a password reset. The
 * ResetPasswordRequest validated it. Apps replace it with
 * Fortify::resetUserPasswordsUsing().
 */
class ResetPassword implements ResetsUserPasswords
{
    /**
     * Set the account's new password.
     *
     * @param  array<string, mixed>  $input
     */
    public function reset(Authenticatable&Model $user, array $input): void
    {
        /** @var string $password */
        $password = $input['password'];

        $user->forceFill([
            $user->getAuthPasswordName() => Hash::make($password),
        ])->save();
    }
}
