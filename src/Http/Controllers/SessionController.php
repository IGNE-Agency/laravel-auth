<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Igne\Auth\Http\Requests\LoginRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/**
 * Logging in and out. Fortify does the work; this controller is the boundary
 * of the package: its request, its answer and its errors are stated here, so
 * anything that reads the routes of an app reads them from this code.
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

    /**
     * Log out.
     *
     * Session mode ends the session; token mode revokes the access token the request was sent with.
     *
     * @response \Illuminate\Http\Response<'', 204>
     */
    public function destroy(Request $request): mixed
    {
        return $this->fortify->destroy($request);
    }
}
