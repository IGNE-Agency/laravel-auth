<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Middleware;

use Closure;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\DeviceTrust;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorRequiredException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorSetupRequiredException;
use Igne\Auth\TwoFactor\TwoFactorEnforcement;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces two-factor authentication on every request of a logged-in user.
 * The provider appends it to the "web" and "api" groups and runs it right
 * after authentication, before throttling.
 *
 * - A guest passes.
 * - A user who must have two-factor authentication but has not set it up gets
 *   "two_factor_setup_required".
 * - A user with two-factor authentication on a session, access token or
 *   device that did not verify it gets "two_factor_required".
 *
 * Routes listed in TwoFactorEnforcement skip the checks, and so do routes
 * that call ->withoutMiddleware() with this class. Without Fortify's
 * two-factor feature, nothing is checked.
 *
 * The middleware answers with the exception's response instead of throwing
 * it, so the responder registered for the request answers even when another
 * exception handler is bound, such as Nova's in a long-running process.
 */
final class EnsureTwoFactorAuthenticated
{
    public function __construct(
        private readonly TwoFactorEnforcement $enforcement,
        private readonly DeviceTrust $deviceTrust,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enforcement->isActive() || $this->enforcement->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof TwoFactorAuthenticatable) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            if ($user->requiresTwoFactorAuthentication() && ! $this->enforcement->isExemptFromSetup($request)) {
                return (new TwoFactorSetupRequiredException)->toResponse($request);
            }

            return $next($request);
        }

        if (! $this->deviceTrust->isTrusted($request, $user)) {
            return (new TwoFactorRequiredException)->toResponse($request);
        }

        return $next($request);
    }
}
