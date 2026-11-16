<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Igne\Auth\Http\Channel;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Fortify\TwoFactorAuthenticatable as FortifyTwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's login step that challenges users with two-factor authentication,
 * except on a remembered device. It is bound to Fortify's contract, so it
 * runs for the package's login route and for Nova's.
 *
 * In token mode the challenge answer carries "challenge": the ID of the header
 * session that holds the pending login, for the "X-Auth-Challenge" header.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private readonly DeviceTrust $deviceTrust,
    ) {
        parent::__construct($guard, $limiter);
    }

    /**
     * @param  Request  $request
     * @param  callable  $next
     */
    public function handle($request, $next): mixed
    {
        $user = $this->validateCredentials($request);

        if (! $this->mustBeChallenged($user)) {
            return $next($request);
        }

        if ($user instanceof TwoFactorAuthenticatable && $this->deviceTrust->isTrustedForLogin($request, $user)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }

    /**
     * Start the challenge. In token mode the pending login moves to a fresh
     * header session, whose ID the client sends back as "X-Auth-Challenge".
     *
     * @param  Request  $request
     * @param  mixed  $user
     */
    protected function twoFactorChallengeResponse($request, $user): Response
    {
        $isTokenMode = Channel::of($request) === Channel::Token;

        if ($isTokenMode) {
            $request->session()->regenerate(true);
        }

        $response = parent::twoFactorChallengeResponse($request, $user);

        if ($isTokenMode && $response instanceof JsonResponse) {
            $response->setData(['two_factor' => true, 'challenge' => $request->session()->getId()]);
        }

        return $response;
    }

    /**
     * Determine whether the user has two-factor authentication enabled, with
     * the same rules as Fortify's own step.
     */
    private function mustBeChallenged(mixed $user): bool
    {
        if (! is_object($user) || ! in_array(FortifyTwoFactorAuthenticatable::class, class_uses_recursive($user), true)) {
            return false;
        }

        $hasSecret = filled(data_get($user, 'two_factor_secret'));

        return Fortify::confirmsTwoFactorAuthentication()
            ? $hasSecret && data_get($user, 'two_factor_confirmed_at') !== null
            : $hasSecret;
    }
}
