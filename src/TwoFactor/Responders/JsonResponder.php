<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Responders;

use Igne\Auth\TwoFactor\Contracts\RespondsToMissingTwoFactor;
use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The default answer: 403 {message, code}, with the code
 * "two_factor_setup_required" or "two_factor_required".
 */
final class JsonResponder implements RespondsToMissingTwoFactor
{
    public function handles(Request $request): bool
    {
        return true;
    }

    public function toResponse(Request $request, MissingTwoFactorAuthenticationException $exception): Response
    {
        return $exception->toJsonResponse();
    }
}
