<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Http\Middleware;

use Closure;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\DeviceTrust;
use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorRequiredException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorSetupRequiredException;
use Igne\Auth\TwoFactor\Responders\JsonResponder;
use Igne\Auth\TwoFactor\TwoFactorEnforcement;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
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
 * For an API request the middleware throws, so the exception handling of the
 * app decides what the 403 looks like. A request another responder handles,
 * such as a page of Nova, is answered by that responder right here, so it
 * answers even when another exception handler is bound, such as Nova's in a
 * long-running process.
 */
final class EnsureTwoFactorAuthenticated
{
    public function __construct(
        private readonly TwoFactorEnforcement $enforcement,
        private readonly DeviceTrust $deviceTrust,
    ) {}

    /**
     * The errors a route can get from this middleware. It sits on every route
     * of the "web" and "api" groups, but it only refuses a logged-in user: a
     * route behind "auth" that is not exempt.
     *
     * @return list<class-string<MissingTwoFactorAuthenticationException>>
     */
    public static function throwsFor(Route $route): array
    {
        $enforcement = Container::getInstance()->make(TwoFactorEnforcement::class);
        $authenticates = collect($route->gatherMiddleware())->contains(static fn (mixed $name): bool => is_string($name) && Str::is(['auth', 'auth:*'], $name));

        return match (true) {
            ! $authenticates, ! $enforcement->isActive(), $enforcement->isRouteExempt($route) => [],
            $enforcement->isRouteExemptFromSetup($route) => [TwoFactorRequiredException::class],
            default => [TwoFactorSetupRequiredException::class, TwoFactorRequiredException::class],
        };
    }

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws TwoFactorSetupRequiredException
     * @throws TwoFactorRequiredException
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
                return $this->refuse($request, new TwoFactorSetupRequiredException);
            }

            return $next($request);
        }

        if (! $this->deviceTrust->isTrusted($request, $user)) {
            return $this->refuse($request, new TwoFactorRequiredException);
        }

        return $next($request);
    }

    /**
     * Throw for a request the default responder answers, and let any other
     * responder answer its own requests.
     */
    private function refuse(Request $request, MissingTwoFactorAuthenticationException $exception): Response
    {
        $responder = $this->enforcement->responderFor($request);

        if ($responder instanceof JsonResponder) {
            throw $exception;
        }

        return $responder->toResponse($request, $exception);
    }
}
