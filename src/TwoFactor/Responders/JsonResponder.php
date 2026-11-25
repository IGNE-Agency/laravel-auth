<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Responders;

use Igne\Auth\TwoFactor\Contracts\RespondsToMissingTwoFactor;
use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The default answer, for the API: the exception itself. It is thrown, so the
 * exception handler of the app answers it like any other error.
 */
final class JsonResponder implements RespondsToMissingTwoFactor
{
    public function handles(Request $request): bool
    {
        return true;
    }

    public function toResponse(Request $request, MissingTwoFactorAuthenticationException $exception): Response
    {
        throw $exception;
    }
}
