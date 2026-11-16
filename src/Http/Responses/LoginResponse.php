<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Responses;

use Igne\Auth\Http\Channel;
use Igne\Auth\Http\Responses\Concerns\IssuesAccessTokenAfterLogin;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Http\Responses\LoginResponse as FortifyLoginResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a successful login. Session mode gets Fortify's own response; token
 * mode gets an access token instead of a logged-in session.
 */
final class LoginResponse implements LoginResponseContract
{
    use IssuesAccessTokenAfterLogin;

    public function __construct(
        private readonly FortifyLoginResponse $sessionResponse,
        private readonly IssuesAccessTokens $tokens,
        private readonly StatefulGuard $guard,
        private readonly DeviceTrust $deviceTrust,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (Channel::of($request) === Channel::Session) {
            return $this->sessionResponse->toResponse($request);
        }

        $user = $this->guard->user();

        if (! $user instanceof Authenticatable) {
            throw new LogicException('A login response needs a logged-in user.');
        }

        $token = $this->issueAccessToken($request, $user);

        // A login that skipped the challenge on a remembered device keeps
        // that device trusted for the new token.
        $this->deviceTrust->bindRememberedDevice($request, $user, $token->id);

        return new JsonResponse([
            'two_factor' => false,
            'token' => $token->plainTextToken,
            'expires_at' => $token->expiresAt?->toJSON(),
        ]);
    }
}
