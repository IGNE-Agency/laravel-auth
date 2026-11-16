<?php

declare(strict_types=1);

namespace Igne\Auth\Nova;

use Igne\Auth\TwoFactor\TwoFactorEnforcement;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Laravel\Nova\Nova;

/**
 * Lets Nova's own screens handle two-factor authentication: its login,
 * challenge and password reset pages skip the enforcement, its User Security
 * page sets two-factor authentication up, and its users are sent to those
 * pages instead of getting a JSON 403.
 */
final class NovaIntegration
{
    public function __construct(
        private readonly Application $app,
        private readonly TwoFactorEnforcement $enforcement,
        private readonly NovaTwoFactorResponder $responder,
    ) {}

    public function boot(): void
    {
        $this->enforcement->except(...$this->exemptions());
        $this->enforcement->exceptSetup(...$this->setupExemptions());
        $this->enforcement->respondUsing($this->responder);

        // Nova registers its routes once the app booted.
        $this->app->booted(function (): void {
            $this->registerPasswordConfirmationRoute();
        });
    }

    /**
     * The Nova routes that skip every check: logging in and out, the
     * challenge, the password reset pages and the assets.
     *
     * @return list<string>
     */
    private function exemptions(): array
    {
        return [
            'nova.pages.login',
            'nova.login',
            'nova.logout',
            'nova.two-factor.login',
            $this->novaPath('user-security/two-factor-challenge'),
            'nova.pages.password.email',
            'nova.password.email',
            'nova.pages.password.reset',
            'nova.password.reset',
            'nova-api/scripts/*',
            'nova-api/styles/*',
        ];
    }

    /**
     * The Nova routes that skip the setup check: the User Security page and
     * its endpoints, which set two-factor authentication up, and the
     * notifications every Nova page loads.
     *
     * @return list<string>
     */
    private function setupExemptions(): array
    {
        return [
            'nova.pages.user-security',
            $this->novaPath('user-security'),
            $this->novaPath('user-security/*'),
            'nova-api/nova-notifications',
            'nova-api/nova-notifications/*',
        ];
    }

    /**
     * Nova sends an Inertia request with a lapsed password confirmation to
     * the route named "password.confirm". Without a frontend that defines it,
     * that route redirects to Nova's own confirmation page.
     */
    private function registerPasswordConfirmationRoute(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        if ($routes->hasNamedRoute('password.confirm')) {
            return;
        }

        $router->redirect('user/confirm-password', Nova::url('/user-security/confirm-password'))->name('password.confirm');

        $routes->refreshNameLookups();
    }

    private function novaPath(string $uri): string
    {
        return ltrim(trim(Nova::path(), '/').'/'.$uri, '/');
    }
}
