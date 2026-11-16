<?php

declare(strict_types=1);

namespace Igne\Auth\Nova;

use Igne\Auth\TwoFactor\Contracts\RespondsToMissingTwoFactor;
use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorRequiredException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Util;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends Nova users to Nova's own two-factor screens:
 *
 * - a user who must set up two-factor authentication goes to User Security;
 * - a user whose device is not trusted is logged out of the session (which
 *   is kept), and goes to Nova's challenge for the same login. Nova's
 *   challenge has no "remember" option, so it trusts the device for
 *   "expires_in_days".
 *
 * An Inertia visit gets Inertia's location response, an XHR call a 401 with
 * {redirect} (which Nova's HTTP client follows), and a page load a redirect.
 */
final class NovaTwoFactorResponder implements RespondsToMissingTwoFactor
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handles(Request $request): bool
    {
        return Util::isNovaRequest($request);
    }

    public function toResponse(Request $request, MissingTwoFactorAuthenticationException $exception): Response
    {
        if (! $exception instanceof TwoFactorRequiredException) {
            return $this->redirect($request, route('nova.pages.user-security'));
        }

        $this->restartLoginAtChallenge($request);

        return $this->redirect($request, route('nova.two-factor.login'));
    }

    /**
     * Log the user out of the session but keep it, with their login pending
     * at the challenge, and return them to this page afterwards.
     */
    private function restartLoginAtChallenge(Request $request): void
    {
        $user = $request->user();
        $guard = $this->auth->guard(Util::userGuard());

        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }

        if (! $user instanceof Authenticatable || ! $request->hasSession()) {
            return;
        }

        $request->session()->put([
            'login.id' => $user->getAuthIdentifier(),
            'login.remember' => false,
        ]);

        if ($request->isMethod('GET') && ! $this->isXhrCall($request)) {
            $request->session()->put('url.intended', $request->fullUrl());
        }
    }

    private function redirect(Request $request, string $url): Response
    {
        if ($this->isXhrCall($request)) {
            return new JsonResponse(['redirect' => $url], Response::HTTP_UNAUTHORIZED);
        }

        return Inertia::location($url);
    }

    /**
     * Determine whether the request is a call of Nova's HTTP client, not an
     * Inertia visit or a page load.
     */
    private function isXhrCall(Request $request): bool
    {
        return $request->expectsJson() && ! $request->hasHeader('X-Inertia');
    }
}
