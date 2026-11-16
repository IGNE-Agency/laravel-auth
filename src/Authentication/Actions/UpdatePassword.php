<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Changes the password of the authenticated account. The UpdatePasswordRequest
 * validated it, including the current password. Apps replace it with
 * Fortify::updateUserPasswordsUsing().
 */
class UpdatePassword implements UpdatesUserPasswords
{
    /**
     * Set the account's new password.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Authenticatable&Model $user, array $input): void
    {
        /** @var string $password */
        $password = $input['password'];

        $user->forceFill([
            $user->getAuthPasswordName() => Hash::make($password),
        ])->save();
    }
}
