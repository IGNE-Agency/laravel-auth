<?php

declare(strict_types=1);

namespace Igne\Auth\Tests;

use Dedoc\Scramble\ScrambleServiceProvider;
use Igne\Auth\AuthenticationServiceProvider;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Assert;
use PragmaRX\Google2FA\Google2FA;
use ReflectionClass;

/**
 * Runs the package on a bare Testbench app with Fortify, Sanctum, Scramble
 * (when installed) and a fixture user, in the provider order of a real app:
 * the package registers first. Testbench migrates every test's in-memory
 * database from defineDatabaseMigrations() and rolls it back afterwards.
 */
abstract class TestCase extends Orchestra
{
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
            ...(class_exists(ScrambleServiceProvider::class) ? [ScrambleServiceProvider::class] : []),
        ];
    }

    /**
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
            // Tests that enforce two-factor authentication switch it on.
            $config->set('authentication.two_factor.required', false);
            $config->set('cache.default', 'array');
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
            $config->set('fortify.views', false);
            $config->set('fortify.limiters.login', null);
            $config->set('fortify.features', [
                Features::resetPasswords(),
                Features::updatePasswords(),
                Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
            ]);
            $config->set('hashing.bcrypt.rounds', 4);
            $config->set('mail.default', 'array');
            $config->set('queue.default', 'sync');
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
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
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
            ->assertMatchesOpenApi('authentication.login')
            ->json('token');

        $this->forgetAuthenticatedUsers();

        return (string) $token;
    }

    /**
     * Get the notification with the last code that was sent to the user.
     * Needs Notification::fake().
     */
    protected function lastLoginCodeNotification(User $user): LoginCodeNotification
    {
        $notification = Notification::sent($user, LoginCodeNotification::class)->last();

        Assert::assertInstanceOf(LoginCodeNotification::class, $notification, 'No login code was sent to the user.');

        return $notification;
    }

    /**
     * Forget the users the guards resolved, as the next request in a fresh
     * process would.
     */
    protected function forgetAuthenticatedUsers(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Log the user in as a bearer-token client up to the two-factor challenge,
     * and get the challenge id.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    protected function loginForChallenge(User $user, array $input = [], array $headers = []): string
    {
        $challenge = $this->withHeaders($headers)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password', ...$input])
            ->assertOk()
            ->assertJson(['two_factor' => true])
            ->json('challenge');

        $this->forgetAuthenticatedUsers();

        return (string) $challenge;
    }

    /**
     * Pass the two-factor challenge of a bearer-token client.
     *
     * @param  array<string, mixed>  $input  the code, or a recovery code, and options
     */
    protected function passChallenge(string $challenge, array $input): TestResponse
    {
        $response = $this->withHeader('X-Auth-Challenge', $challenge)->postJson('/api/auth/two-factor-challenge', $input);

        $this->flushHeaders();
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    /**
     * Get a valid code from the user's authenticator app. Fortify accepts each
     * code once, so a second code in a test takes the next period's.
     */
    protected function twoFactorCode(User $user, int $periodsAhead = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp(
            Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
            $google2fa->getTimestamp() + $periodsAhead,
        );
    }

    /**
     * Get one of the user's recovery codes.
     */
    protected function recoveryCode(User $user): string
    {
        $recoveryCode = $user->recoveryCodes()[0] ?? null;

        Assert::assertIsString($recoveryCode, 'The user has no recovery codes.');

        return $recoveryCode;
    }
}
