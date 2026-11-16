<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures\Nova;

use Laravel\Fortify\Features;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;

/**
 * The Nova setup of an app that runs the package: Nova's own login, challenge
 * and User Security screens with Fortify's two-factor feature, and a user
 * resource with the package's reset action. The tests define the
 * "viewNova" gate.
 */
final class NovaServiceProvider extends NovaApplicationServiceProvider
{
    protected function fortify(): void
    {
        Nova::fortify()
            ->features([
                Features::updatePasswords(),
                Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ])
            ->register();
    }

    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(['nova:auth'], true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    protected function resources(): void
    {
        Nova::resources([User::class]);
    }
}
