<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\ForgotPasswordRequest;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;

final class PasswordResetLinkController
{
    public function __construct(private readonly FortifyPasswordResetLinkController $fortify) {}

    /**
     * Request a password reset link.
     *
     * Always answers the same for a valid email address, whether or not an account exists. Only a user that can
     * log in with a password gets a link.
     *
     * @response array{message: string}
     */
    public function store(ForgotPasswordRequest $request): mixed
    {
        return $this->fortify->store($request);
    }
}
