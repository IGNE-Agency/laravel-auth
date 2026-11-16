<?php

declare(strict_types=1);

namespace Igne\Auth\Tokens\Contracts;

use Igne\Auth\Tokens\AccessToken;
use Igne\Auth\Tokens\NewAccessToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Issues and revokes the access tokens of bearer-token clients. The default
 * issuer uses Sanctum; a Passport project swaps it in
 * "authentication.tokens.issuer".
 */
interface IssuesAccessTokens
{
    /**
     * Issue a new access token for the user, with the lifetime from
     * "authentication.tokens.expires_in_minutes".
     */
    public function issue(Authenticatable $user, string $name): NewAccessToken;

    /**
     * Revoke the access token the user authenticated the current request with.
     * Does nothing when the request was not authenticated with a token.
     */
    public function revokeCurrent(Authenticatable $user, string $reason = 'logout'): void;

    /**
     * Revoke all access tokens of the user.
     */
    public function revokeAll(Authenticatable $user, string $reason = 'revoked'): void;

    /**
     * Get the id of the access token the user authenticated the current
     * request with, or null when the request was not authenticated with one.
     */
    public function currentTokenId(Authenticatable $user): ?string;

    /**
     * List the user's access tokens, newest first.
     *
     * @return Collection<int, AccessToken>
     */
    public function list(Authenticatable $user): Collection;
}
