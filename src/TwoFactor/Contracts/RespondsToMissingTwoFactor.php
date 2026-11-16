<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Contracts;

use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a request whose user must set up or pass two-factor authentication
 * first. Register one with TwoFactorEnforcement::respondUsing(), e.g. to send
 * an admin panel's users to its own screens; the JSON responder answers the
 * requests no other responder handles.
 */
interface RespondsToMissingTwoFactor
{
    /**
     * Determine whether the responder answers the request.
     */
    public function handles(Request $request): bool;

    /**
     * Answer the request. The exception is a TwoFactorSetupRequiredException
     * or a TwoFactorRequiredException.
     */
    public function toResponse(Request $request, MissingTwoFactorAuthenticationException $exception): Response;
}
