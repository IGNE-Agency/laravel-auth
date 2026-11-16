<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\UpdatePasswordRequest;
use Laravel\Fortify\Contracts\PasswordUpdateResponse;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Http\Controllers\PasswordController as FortifyPasswordController;

/**
 * Fortify's password change, with the input validated by UpdatePasswordRequest.
 */
final class PasswordController extends FortifyPasswordController
{
    public function __invoke(UpdatePasswordRequest $request, UpdatesUserPasswords $updater): PasswordUpdateResponse
    {
        return $this->update($request, $updater);
    }
}
