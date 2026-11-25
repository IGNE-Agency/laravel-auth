<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\LoginRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/**
 * Logging in. Fortify does the work. The package adds inputs and answers of
 * its own to the login (token mode, device trust, login codes), which this
 * controller and its Form Request declare; Fortify's own know nothing of them.
 */
final class SessionController
{
    public function __construct(private readonly AuthenticatedSessionController $fortify) {}

    /**
     * Log in.
     *
     * With an emailed login code, send the code as "password". Session mode logs the cookie session in; token mode
     * answers an access token for the Authorization: Bearer header. With "two_factor": true nobody is logged in
     * yet: pass POST /auth/two-factor-challenge next, in token mode with "challenge" as the X-Auth-Challenge header.
     *
     * @response array{two_factor: bool, token?: string, expires_at?: string|null, challenge?: string}
     *
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function store(LoginRequest $request): mixed
    {
        return $this->fortify->store($request);
    }
}
