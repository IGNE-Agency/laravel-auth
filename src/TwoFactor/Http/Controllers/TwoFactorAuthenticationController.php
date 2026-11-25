<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Controllers;

use Igne\Auth\TwoFactor\Http\Requests\ConfirmTwoFactorRequest;
use Igne\Auth\TwoFactor\Http\Requests\EnableTwoFactorRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController as FortifyTwoFactorAuthenticationController;

/**
 * Setting two-factor authentication up and turning it off.
 */
final class TwoFactorAuthenticationController
{
    /**
     * Set up two-factor authentication.
     *
     * Creates a new authenticator secret and recovery codes. Show the QR code of GET /user/two-factor-qr-code,
     * then confirm a code of the authenticator app with POST /user/confirmed-two-factor-authentication.
     *
     * @response \Illuminate\Http\JsonResponse<'', 200>
     */
    public function store(EnableTwoFactorRequest $request, FortifyTwoFactorAuthenticationController $fortify, EnableTwoFactorAuthentication $enable): mixed
    {
        return $fortify->store($request, $enable);
    }

    /**
     * Confirm two-factor authentication.
     *
     * Finishes the setup with a code of the authenticator app.
     *
     * @response \Illuminate\Http\JsonResponse<'', 200>
     *
     * @throws ValidationException
     */
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmedTwoFactorAuthenticationController $fortify, ConfirmTwoFactorAuthentication $confirm): mixed
    {
        return $fortify->store($request, $confirm);
    }

    /**
     * Turn two-factor authentication off.
     *
     * Removes the secret and the recovery codes, and revokes the trusted devices.
     *
     * @response \Illuminate\Http\JsonResponse<'', 200>
     */
    public function destroy(Request $request, FortifyTwoFactorAuthenticationController $fortify, DisableTwoFactorAuthentication $disable): mixed
    {
        return $fortify->destroy($request, $disable);
    }
}
