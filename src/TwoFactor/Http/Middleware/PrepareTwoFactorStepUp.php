<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Middleware;

use Closure;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a logged-in user pass the two-factor challenge again, when the trust
 * of their session, access token or device lapsed: it starts Fortify's
 * challenge for the authenticated user, so they don't log in again. A pending
 * login challenge goes first.
 */
final class PrepareTwoFactorStepUp
{
    /**
     * The request attribute that holds the user who steps up.
     */
    public const string USER_ATTRIBUTE = 'authentication.two_factor.step_up_user';

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->session()->has('login.id')) {
            return $next($request);
        }

        $user = $this->auth->guard('sanctum')->user();

        if ($user instanceof TwoFactorAuthenticatable && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getAuthIdentifier(),
                'login.remember' => false,
            ]);

            $request->attributes->set(self::USER_ATTRIBUTE, $user);
        }

        return $next($request);
    }
}
