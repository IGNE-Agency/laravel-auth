<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Controllers;

use Igne\Auth\TwoFactor\Http\Requests\TwoFactorChallengeRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

final class TwoFactorChallengeController
{
    public function __construct(private readonly TwoFactorAuthenticatedSessionController $fortify) {}

    /**
     * Pass the two-factor challenge.
     *
     * Send a "code" of the authenticator app or a "recovery_code". After a login it finishes the login: session
     * mode answers 204, token mode an access token. Sent by a logged-in user whose device is not trusted, it
     * trusts the device again.
     *
     * @response \Illuminate\Http\Response<'', 204>|array{token?: string, expires_at?: string|null, device_token?: string|null}
     *
     * @throws ValidationException
     */
    public function store(TwoFactorChallengeRequest $request): mixed
    {
        return $this->fortify->store($request);
    }
}
