<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Responses\Concerns;

use Igne\Auth\Http\DeviceName;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\Tokens\NewAccessToken;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * Turns a login Fortify completed on the session guard into an access token
 * for a token-mode client.
 *
 * @property-read IssuesAccessTokens $tokens
 * @property-read StatefulGuard $guard
 */
trait IssuesAccessTokenAfterLogin
{
    /**
     * Issue the access token, named after the client device, and drop the
     * header session and the session guard's login.
     */
    protected function issueAccessToken(Request $request, Authenticatable $user): NewAccessToken
    {
        $token = $this->tokens->issue($user, DeviceName::of($request));

        $request->session()->invalidate();
        $this->forgetSessionLogin();

        return $token;
    }

    /**
     * Fortify logged the user into the session guard to get here. A token
     * client never gets that session, so the guard forgets the login: the
     * user, and the login id in the guard's own (never saved) store.
     */
    protected function forgetSessionLogin(): void
    {
        if ($this->guard instanceof SessionGuard) {
            $this->guard->getSession()->forget($this->guard->getName());
            $this->guard->forgetUser();
        }
    }
}
