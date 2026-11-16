<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Responses;

use Igne\Auth\Http\Channel;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    public function __construct(
        private readonly FortifyLoginResponse $sessionResponse,
        private readonly IssuesAccessTokens $tokens,
        private readonly StatefulGuard $guard,
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

        $token = $this->tokens->issue($user, $this->tokenName($request));

        $request->session()->invalidate();
        $this->forgetSessionLogin();

        return new JsonResponse([
            'two_factor' => false,
            'token' => $token->plainTextToken,
            'expires_at' => $token->expiresAt?->toJSON(),
        ]);
    }

    /**
     * Get the token name: the "device_name" field, or the user agent.
     */
    private function tokenName(Request $request): string
    {
        $deviceName = $request->input('device_name');
        $name = is_string($deviceName) && trim($deviceName) !== '' ? trim($deviceName) : (string) $request->userAgent();

        return Str::limit($name !== '' ? $name : 'Unknown device', 255, '');
    }

    /**
     * Fortify logged the user into the session guard to get here. A token
     * client never gets that session, so the guard forgets the login: the
     * user, and the login id in the guard's own (never saved) store.
     */
    private function forgetSessionLogin(): void
    {
        if ($this->guard instanceof SessionGuard) {
            $this->guard->getSession()->forget($this->guard->getName());
            $this->guard->forgetUser();
        }
    }
}
