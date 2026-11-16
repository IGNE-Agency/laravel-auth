<?php

declare(strict_types=1);

namespace Igne\Auth\Tests;

use Igne\Auth\AuthenticationServiceProvider;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/**
 * Runs the package on a bare Testbench app with Fortify, Sanctum and a fixture
 * user, in the provider order of a real app: the package registers first.
 *
 * The suite also runs inside a host app's test run, which shares Laravel's
 * static RefreshDatabase state with a different schema. So the suite uses no
 * RefreshDatabase trait: Testbench migrates every test's in-memory database
 * from defineDatabaseMigrations() and rolls it back afterwards.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // A host app's earlier tests may have left these static callbacks set.
        ResetPassword::createUrlUsing(null);
        ResetPassword::toMailUsing(null);
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AuthenticationServiceProvider::class,
            FortifyServiceProvider::class,
            SanctumServiceProvider::class,
        ];
    }

    /**
     * Configure the app explicitly: inside a host app, the host's environment
     * variables are already loaded into the process.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), static function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('app.locale', 'en');
            $config->set('app.fallback_locale', 'en');
            $config->set('auth.providers.users.model', User::class);
            $config->set('auth.timebox_duration', 0);
            $config->set('cache.default', 'array');
            $config->set('database.default', 'testing');
            $config->set('fortify.views', false);
            $config->set('fortify.limiters.login', null);
            $config->set('fortify.features', [Features::resetPasswords(), Features::updatePasswords()]);
            $config->set('session.driver', 'array');
            $config->set('sanctum.stateful', ['localhost']);
        });

        $kernel = $app->make(Kernel::class);

        if ($kernel instanceof HttpKernel) {
            $kernel->prependMiddlewareToGroup('api', EnsureFrontendRequestsAreStateful::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(dirname((string) (new ReflectionClass(Sanctum::class))->getFileName(), 2).'/database/migrations');
    }

    /**
     * Send the next requests as the SPA: Sanctum's stateful stack then runs,
     * which puts them in session mode.
     */
    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', 'http://localhost');
    }

    /**
     * Log the user in as a bearer-token client and get the plain-text token.
     *
     * @param  array<string, mixed>  $input
     */
    protected function loginForToken(User $user, string $password = 'password', array $input = []): string
    {
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password, ...$input])
            ->assertOk()
            ->json('token');

        $this->forgetAuthenticatedUsers();

        return (string) $token;
    }

    /**
     * Forget the users the guards resolved, as the next request in a fresh
     * process would.
     */
    protected function forgetAuthenticatedUsers(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
