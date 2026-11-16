<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Igne\Auth\TwoFactor\Contracts\RespondsToMissingTwoFactor;
use Igne\Auth\TwoFactor\Responders\JsonResponder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

/**
 * The routes the two-factor enforcement skips, and the responders that answer
 * a request whose user must set up or pass two-factor authentication.
 *
 * Exemptions are route names or URI patterns ("*" wildcards). Those from
 * except() and "authentication.two_factor.except" skip every check; those
 * from exceptSetup() and "authentication.two_factor.except_setup" only skip
 * the setup check, so users without two-factor authentication can reach the
 * screens that set it up.
 */
final class TwoFactorEnforcement
{
    /**
     * @var list<string>
     */
    private array $except = [];

    /**
     * @var list<string>
     */
    private array $exceptSetup = [];

    /**
     * @var list<RespondsToMissingTwoFactor>
     */
    private array $responders = [];

    public function __construct(private readonly Repository $config) {}

    /**
     * Skip every two-factor check on the given route names or URI patterns.
     */
    public function except(string ...$patterns): void
    {
        $this->except = array_values(array_unique([...$this->except, ...$patterns]));
    }

    /**
     * Skip the setup check on the given route names or URI patterns.
     */
    public function exceptSetup(string ...$patterns): void
    {
        $this->exceptSetup = array_values(array_unique([...$this->exceptSetup, ...$patterns]));
    }

    /**
     * Let a responder answer the requests it handles, before the responders
     * registered earlier.
     */
    public function respondUsing(RespondsToMissingTwoFactor $responder): void
    {
        array_unshift($this->responders, $responder);
    }

    /**
     * Determine whether two-factor authentication is enforced at all: only
     * with Fortify's two-factor feature, which users set it up with.
     */
    public function isActive(): bool
    {
        return Features::enabled(Features::twoFactorAuthentication());
    }

    /**
     * Determine whether the request skips every two-factor check.
     */
    public function isExempt(Request $request): bool
    {
        return $this->matches($request->route()?->getName(), $request->decodedPath(), $this->patterns('except'));
    }

    /**
     * Determine whether the request skips the setup check.
     */
    public function isExemptFromSetup(Request $request): bool
    {
        return $this->isExempt($request)
            || $this->matches($request->route()?->getName(), $request->decodedPath(), $this->patterns('except_setup'));
    }

    /**
     * Determine whether the route skips every two-factor check.
     */
    public function isRouteExempt(Route $route): bool
    {
        return $this->matches($route->getName(), $route->uri(), $this->patterns('except'));
    }

    /**
     * Determine whether the route skips the setup check.
     */
    public function isRouteExemptFromSetup(Route $route): bool
    {
        return $this->isRouteExempt($route)
            || $this->matches($route->getName(), $route->uri(), $this->patterns('except_setup'));
    }

    /**
     * Get the responder that answers the request.
     */
    public function responderFor(Request $request): RespondsToMissingTwoFactor
    {
        foreach ($this->responders as $responder) {
            if ($responder->handles($request)) {
                return $responder;
            }
        }

        return new JsonResponder;
    }

    /**
     * Get the registered and configured patterns of an exemption list.
     *
     * @param  'except'|'except_setup'  $list
     * @return list<string>
     */
    private function patterns(string $list): array
    {
        $configured = $this->config->get("authentication.two_factor.{$list}", []);

        return [
            ...($list === 'except' ? $this->except : $this->exceptSetup),
            ...array_values(array_filter(is_array($configured) ? $configured : [], is_string(...))),
        ];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(?string $routeName, string $path, array $patterns): bool
    {
        $path = trim($path, '/');

        foreach ($patterns as $pattern) {
            if (($routeName !== null && Str::is($pattern, $routeName)) || Str::is(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
