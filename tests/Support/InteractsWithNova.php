<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Support;

use Igne\Auth\Tests\Fixtures\Nova\NovaServiceProvider;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaCoreServiceProvider;
use ReflectionClass;

/**
 * Runs the tests on the package's test app with Nova and the fixture Nova
 * setup. Nova is paid and only suggested, so without it the tests are skipped.
 */
trait InteractsWithNova
{
    protected function setUpInteractsWithNova(): void
    {
        if (! class_exists(Nova::class)) {
            $this->markTestSkipped('Nova is not installed.');
        }

        // Nova's assets are not built in the package, so the layout renders without them.
        $this->withoutMix();
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        if (! class_exists(Nova::class)) {
            return parent::getPackageProviders($app);
        }

        return [
            ...parent::getPackageProviders($app),
            InertiaServiceProvider::class,
            NovaCoreServiceProvider::class,
            NovaServiceProvider::class,
        ];
    }

    /**
     * Also migrate Nova's tables: action events and notifications.
     */
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        if (class_exists(Nova::class)) {
            $this->loadMigrationsFrom(dirname((string) (new ReflectionClass(Nova::class))->getFileName(), 2).'/database/migrations');
        }
    }

    /**
     * Get the URL of a Nova route without a name, on Nova's domain.
     */
    protected function novaUrl(string $path): string
    {
        return Str::beforeLast(route('nova.pages.login'), '/login').$path;
    }

    /**
     * Get the URL of a Nova API route, on Nova's domain.
     */
    protected function novaApiUrl(string $path): string
    {
        return Str::beforeLast(route('nova.pages.login'), Nova::path().'/login').'/nova-api'.$path;
    }

    /**
     * Trust the test's cookie session for the user, as passing the two-factor
     * challenge in it does. The testing session store keeps it for the next
     * requests.
     */
    protected function trustTwoFactorSession(User $user): void
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app->make('session.store'));

        $this->app->make(DeviceTrust::class)->trustSession($request, $user);
    }
}
