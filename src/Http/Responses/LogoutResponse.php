<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Responses;

use Igne\Auth\Http\Channel;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a logout. In token mode it revokes the token the request was
 * authenticated with. Nova's logout uses this response too, without a token
 * user, so the revocation is null-safe.
 */
final class LogoutResponse implements LogoutResponseContract
{
    public function __construct(
        private readonly FortifyLogoutResponse $fortifyResponse,
        private readonly IssuesAccessTokens $tokens,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (Channel::of($request) === Channel::Token) {
            $user = $request->user();

            if ($user instanceof Authenticatable) {
                $this->tokens->revokeCurrent($user, 'logout');
            }
        }

        return $this->fortifyResponse->toResponse($request);
    }
}
