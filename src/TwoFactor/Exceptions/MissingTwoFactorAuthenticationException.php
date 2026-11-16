<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Exceptions;

use Igne\Auth\Http\Exceptions\AuthenticationException;
use Igne\Auth\TwoFactor\TwoFactorEnforcement;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The user must set up or pass two-factor authentication before the request
 * can continue. The responder registered for the request renders it; by
 * default that is a JSON 403 {message, code}.
 */
abstract class MissingTwoFactorAuthenticationException extends AuthenticationException
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return Container::getInstance()->make(TwoFactorEnforcement::class)
            ->responderFor($request)
            ->toResponse($request, $this);
    }
}
