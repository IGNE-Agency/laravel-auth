<?php

declare(strict_types=1);

namespace Igne\Auth;

use Dedoc\Scramble\Configuration\GeneratorConfigCollection;
use Dedoc\Scramble\Scramble;
use Igne\Auth\Authentication\Actions\ResetPassword;
use Igne\Auth\Authentication\Actions\UpdatePassword;
use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Http\Middleware\ConcealPasswordResetOutcome;
use Igne\Auth\Http\Middleware\EnsurePasswordAuthenticatable;
use Igne\Auth\Http\Responses\LoginResponse;
use Igne\Auth\Http\Responses\LogoutResponse;
use Igne\Auth\OpenApi\AuthenticationExceptionToResponseExtension;
use Igne\Auth\OpenApi\AuthenticationOperationTransformer;
use Igne\Auth\OpenApi\RemoveReplacedSchemasDocumentTransformer;
use Igne\Auth\OpenApi\SecuritySchemesDocumentTransformer;
use Igne\Auth\Sessions\HeaderSessionFactory;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Wires the package into Fortify and Sanctum.
 *
 * The provider registers before Fortify's (igne-agency sorts before laravel),
 * and Fortify's register() binds its own response classes. So every Fortify
 * binding and callback is set in boot(), after all providers registered.
 */
final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::path('config/authentication.php'), 'authentication');

        Fortify::ignoreRoutes();

        $this->app->scoped(IssuesAccessTokens::class, static function (Application $app): IssuesAccessTokens {
            /** @var class-string<IssuesAccessTokens> $issuer */
            $issuer = $app->make('config')->get('authentication.tokens.issuer');

            return $app->make($issuer);
        });

        $this->app->scoped(HeaderSessionFactory::class);
    }

    public function boot(): void
    {
        $this->configurePublishing();
        $this->loadTranslationsFrom(self::path('lang'), 'authentication');

        $this->configureFortify();
        $this->configureTokens();
        $this->configureMiddleware();
        $this->configureRateLimiting();
        $this->configureRoutes();
        $this->configureSchedule();
        $this->configureOpenApi();
    }

    private function configurePublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            self::path('config/authentication.php') => $this->app->configPath('authentication.php'),
        ], 'authentication-config');

        $this->publishes([
            self::path('lang') => $this->app->langPath('vendor/authentication'),
        ], 'authentication-lang');

        $this->publishesMigrations([
            self::path('database/migrations') => $this->app->databasePath('migrations'),
        ], 'authentication-migrations');
    }

    /**
     * Point Fortify at the package's credential check, actions and responses.
     * Apps override the actions with the same Fortify calls in their own
     * providers, which boot after this one.
     */
    private function configureFortify(): void
    {
        // Nova keeps the first callback it sees in a static property, so the
        // callback resolves the resolver from the current container instead of
        // capturing this provider's application.
        Fortify::authenticateUsing(static fn (Request $request) => Container::getInstance()->make(AuthenticateCredentials::class)($request));
        Fortify::resetUserPasswordsUsing(ResetPassword::class);
        Fortify::updateUserPasswordsUsing(UpdatePassword::class);

        $this->app->scoped(LoginResponseContract::class, LoginResponse::class);
        $this->app->scoped(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Reject Sanctum tokens that were not used for longer than
     * "authentication.tokens.idle_expires_in_minutes".
     */
    private function configureTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $isValid): bool {
            $idleMinutes = config('authentication.tokens.idle_expires_in_minutes');

            if (! $isValid || $idleMinutes === null) {
                return $isValid;
            }

            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $lastActivity !== null && $lastActivity->greaterThan(Date::now()->subMinutes((int) $idleMinutes));
        });
    }

    /**
     * Register the middleware aliases, and run the header session right after
     * where a cookie session would start: before authentication and throttling.
     */
    private function configureMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('authentication.accept-json', AcceptJson::class);
        $router->aliasMiddleware('authentication.header-session', StartHeaderSession::class);
        $router->aliasMiddleware('authentication.password-authenticatable', EnsurePasswordAuthenticatable::class);
        $router->aliasMiddleware('authentication.conceal-password-reset', ConcealPasswordResetOutcome::class);

        $this->app->make(HttpKernel::class)->addToMiddlewarePriorityAfter(StartSession::class, StartHeaderSession::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('authentication-password-reset', static function (Request $request): array {
            $email = $request->input(Fortify::email());

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perMinute(3)->by('email:'.(is_string($email) ? Str::lower($email) : '')),
            ];
        });
    }

    private function configureRoutes(): void
    {
        if (! config('authentication.routes.enabled') || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }

        /** @var list<string> $middleware */
        $middleware = config('authentication.routes.middleware', []);

        Route::prefix((string) config('authentication.routes.prefix'))
            ->middleware([AcceptJson::class, ...$middleware])
            ->group(self::path('routes/authentication.php'));
    }

    private function configureSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (! config('authentication.schedule')) {
                return;
            }

            $schedule->command('sanctum:prune-expired', ['--hours' => 24])->daily();
        });
    }

    /**
     * Document the package routes in the app's Scramble spec, when the app
     * uses Scramble.
     */
    private function configureOpenApi(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::registerExtension(AuthenticationExceptionToResponseExtension::class);

        $this->callAfterResolving(GeneratorConfigCollection::class, static function (GeneratorConfigCollection $apis): void {
            $apis->get(Scramble::DEFAULT_API)
                ->withOperationTransformers(AuthenticationOperationTransformer::class)
                ->withDocumentTransformers([SecuritySchemesDocumentTransformer::class, RemoveReplacedSchemasDocumentTransformer::class]);
        });
    }

    private static function path(string $path): string
    {
        return dirname(__DIR__).'/'.$path;
    }
}
