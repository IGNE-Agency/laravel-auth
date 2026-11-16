<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\ResetPasswordRequest;
use Illuminate\Contracts\Support\Responsable;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;

/**
 * Fortify's password reset, with the input validated by ResetPasswordRequest.
 */
final class NewPasswordController extends FortifyNewPasswordController
{
    public function __invoke(ResetPasswordRequest $request): Responsable
    {
        return $this->store($request);
    }
}
