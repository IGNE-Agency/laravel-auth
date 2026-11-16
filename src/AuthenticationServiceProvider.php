<?php

declare(strict_types=1);

namespace Igne\Auth;

use Dedoc\Scramble\Scramble;
use Igne\Auth\Authentication\Actions\ResetPassword;
use Igne\Auth\Authentication\Actions\UpdatePassword;
use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Authentication\ConfirmIdentity;
use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Http\Middleware\ConcealPasswordResetOutcome;
use Igne\Auth\Http\Middleware\EnsurePasswordAuthenticatable;
use Igne\Auth\Http\Responses\LoginResponse;
use Igne\Auth\Http\Responses\LogoutResponse;
use Igne\Auth\LoginCodes\Http\Responses\LoginCodeRequestedResponse;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\OpenApi\Scramble\ScrambleIntegration;
use Igne\Auth\Sessions\HeaderSessionFactory;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
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
use LogicException;
use Symfony\Component\HttpFoundation\Response;

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
            $issuer = $app->make(Repository::class)->string('authentication.tokens.issuer');
            $tokenIssuer = $app->make($issuer);

            if (! $tokenIssuer instanceof IssuesAccessTokens) {
                throw new LogicException("The access token issuer [{$issuer}] must implement [".IssuesAccessTokens::class.'].');
            }

            return $tokenIssuer;
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
        // Nova keeps the first callbacks it sees in static properties, so the
        // callbacks resolve their classes from the current container instead
        // of capturing this provider's application.
        Fortify::authenticateUsing(static fn (Request $request) => Container::getInstance()->make(AuthenticateCredentials::class)($request));
        Fortify::confirmPasswordsUsing(static fn (mixed $user, ?string $password = null): bool => Container::getInstance()->make(ConfirmIdentity::class)($user, $password));
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
            if (! $isValid || config('authentication.tokens.idle_expires_in_minutes') === null) {
                return $isValid;
            }

            $idleMinutes = config()->integer('authentication.tokens.idle_expires_in_minutes');
            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $lastActivity !== null && $lastActivity->greaterThan(Date::now()->subMinutes($idleMinutes));
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

        // A throttled login code request gets the same answer as any other,
        // so the limit doesn't reveal that the address was used before.
        RateLimiter::for('authentication-login-code', static function (Request $request): array {
            $username = $request->input(Fortify::username());
            $concealedResponse = static fn (Request $request): Response => (new LoginCodeRequestedResponse)->toResponse($request);

            return [
                self::loginCodeRequestLimit('max_requests_per_ip')->by('ip:'.$request->ip())->response($concealedResponse),
                self::loginCodeRequestLimit('max_requests_per_email')
                    ->by('email:'.(is_string($username) ? Str::lower(trim($username)) : ''))
                    ->response($concealedResponse),
            ];
        });

        RateLimiter::for('authentication-confirmation-code', static function (Request $request): array {
            $user = $request->user();
            $userId = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

            return [
                self::loginCodeRequestLimit('max_requests_per_ip')->by('ip:'.$request->ip()),
                self::loginCodeRequestLimit('max_requests_per_email')->by('user:'.(is_scalar($userId) ? (string) $userId : '')),
            ];
        });
    }

    /**
     * Get a limit on sending codes from "authentication.login_codes.rate_limit_requests".
     */
    private static function loginCodeRequestLimit(string $maxRequestsKey): Limit
    {
        return Limit::perSecond(
            config()->integer("authentication.login_codes.rate_limit_requests.{$maxRequestsKey}"),
            config()->integer('authentication.login_codes.rate_limit_requests.time_window_in_seconds', 60),
        );
    }

    private function configureRoutes(): void
    {
        if (! config('authentication.routes.enabled') || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }

        $middleware = config()->array('authentication.routes.middleware', []);

        Route::prefix(config()->string('authentication.routes.prefix'))
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
            $schedule->command('model:prune', ['--model' => [config()->string('authentication.login_codes.model', LoginCode::class)]])->daily();
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

        $this->app->make(ScrambleIntegration::class)->boot();
    }

    private static function path(string $path): string
    {
        return dirname(__DIR__).'/'.$path;
    }
}
