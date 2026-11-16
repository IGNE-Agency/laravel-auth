<?php

declare(strict_types=1);

namespace Igne\Auth;

use Dedoc\Scramble\Scramble;
use Igne\Auth\Audit\Listeners\AuditEventSubscriber;
use Igne\Auth\Audit\Models\AuditLog;
use Igne\Auth\Authentication\Actions\ResetPassword;
use Igne\Auth\Authentication\Actions\UpdatePassword;
use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Authentication\ConfirmIdentity;
use Igne\Auth\Events\TwoFactorAuthenticationReset;
use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Http\Middleware\ConcealPasswordResetOutcome;
use Igne\Auth\Http\Middleware\EnsurePasswordAuthenticatable;
use Igne\Auth\Http\Responses\LoginResponse;
use Igne\Auth\Http\Responses\LogoutResponse;
use Igne\Auth\Http\Responses\TwoFactorLoginResponse;
use Igne\Auth\LoginCodes\Http\Responses\LoginCodeRequestedResponse;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\Nova\NovaIntegration;
use Igne\Auth\OpenApi\Scramble\ScrambleIntegration;
use Igne\Auth\Sessions\HeaderSessionFactory;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\Console\ResetTwoFactorAuthenticationCommand;
use Igne\Auth\TwoFactor\DeviceTrust;
use Igne\Auth\TwoFactor\DeviceTrustLifetime;
use Igne\Auth\TwoFactor\Http\Middleware\EnsureTwoFactorAuthenticated;
use Igne\Auth\TwoFactor\Http\Middleware\PrepareTwoFactorStepUp;
use Igne\Auth\TwoFactor\Listeners\IssueDeviceTrust;
use Igne\Auth\TwoFactor\Listeners\RememberRecoveryCodeUse;
use Igne\Auth\TwoFactor\Listeners\RevokeDeviceTrust;
use Igne\Auth\TwoFactor\Listeners\SendTwoFactorAuthenticationResetNotification;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Igne\Auth\TwoFactor\RedirectIfTwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\TwoFactorEnforcement;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Nova\NovaCoreServiceProvider;
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
        $this->app->scoped(DeviceTrust::class);
        $this->app->singleton(TwoFactorEnforcement::class);
    }

    public function boot(): void
    {
        $this->configurePublishing();
        $this->loadTranslationsFrom(self::path('lang'), 'authentication');

        $this->configureFortify();
        $this->configureTokens();
        $this->configureTwoFactor();
        $this->configureAudit();
        $this->configureMiddleware();
        $this->configureRateLimiting();
        $this->configureRoutes();
        $this->configureCommands();
        $this->configureSchedule();
        $this->configureNova();
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

        // Nova rebinds only Fortify's concrete class, so this contract binding
        // also runs for Nova's login.
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->app->scoped(LoginResponseContract::class, LoginResponse::class);
        $this->app->scoped(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->scoped(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
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
     * Validate the device trust settings, hook the device trust into Fortify's
     * and the package's events, and exempt the package's own routes from the
     * checks they serve.
     */
    private function configureTwoFactor(): void
    {
        DeviceTrustLifetime::fromConfig($this->app->make('config'));

        $events = $this->app->make(Dispatcher::class);
        $events->listen([ValidTwoFactorAuthenticationCodeProvided::class, TwoFactorAuthenticationConfirmed::class], IssueDeviceTrust::class);
        $events->listen(RecoveryCodeReplaced::class, RememberRecoveryCodeUse::class);
        $events->listen([TwoFactorAuthenticationDisabled::class, PasswordUpdatedViaController::class, PasswordReset::class], RevokeDeviceTrust::class);
        $events->listen(TwoFactorAuthenticationReset::class, SendTwoFactorAuthenticationResetNotification::class);

        // Trust that is bound to an access token ends with the token. The app
        // may swap Sanctum's token model while it boots.
        $this->app->booted(static function () use ($events): void {
            $events->listen('eloquent.deleted: '.Sanctum::personalAccessTokenModel(), static function (PersonalAccessToken $token): void {
                $tokenId = $token->getKey();

                if (! is_int($tokenId) && ! is_string($tokenId)) {
                    throw new LogicException(sprintf('The access token model [%s] needs an integer or string key.', $token::class));
                }

                Container::getInstance()->make(DeviceTrust::class)->forgetAccessToken((string) $tokenId);
            });
        });

        // Apps allow resetting the two-factor authentication of others by
        // defining this gate themselves.
        if (! Gate::has('resetTwoFactorAuthentication')) {
            Gate::define('resetTwoFactorAuthentication', static fn (mixed $user, mixed $target = null): bool => false);
        }

        $enforcement = $this->app->make(TwoFactorEnforcement::class);
        $enforcement->except(
            'authentication.login',
            'authentication.logout',
            'authentication.login-code',
            'authentication.two-factor.challenge',
            'authentication.user',
            'authentication.password.confirm.*',
            'authentication.password.confirmation',
        );
        $enforcement->exceptSetup('authentication.two-factor.*');
    }

    /**
     * Write the security events to the audit log. The subscriber listens
     * after the package's own listeners, so it sees the factors they record.
     */
    private function configureAudit(): void
    {
        $this->app->make(Dispatcher::class)->subscribe(AuditEventSubscriber::class);
    }

    /**
     * Register the middleware aliases. The header session runs right after
     * where a cookie session would start, before authentication and
     * throttling; the two-factor enforcement runs in the "web" and "api"
     * groups, right after authentication.
     */
    private function configureMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('authentication.accept-json', AcceptJson::class);
        $router->aliasMiddleware('authentication.header-session', StartHeaderSession::class);
        $router->aliasMiddleware('authentication.password-authenticatable', EnsurePasswordAuthenticatable::class);
        $router->aliasMiddleware('authentication.conceal-password-reset', ConcealPasswordResetOutcome::class);
        $router->aliasMiddleware('authentication.two-factor', EnsureTwoFactorAuthenticated::class);

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->addToMiddlewarePriorityAfter(StartSession::class, StartHeaderSession::class);
        $kernel->addToMiddlewarePriorityAfter(StartHeaderSession::class, PrepareTwoFactorStepUp::class);
        $kernel->addToMiddlewarePriorityAfter(AuthenticatesRequests::class, EnsureTwoFactorAuthenticated::class);

        foreach (['web', 'api'] as $group) {
            if (array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, EnsureTwoFactorAuthenticated::class);
            }
        }
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

        // Five codes a minute per pending challenge (the challenged user), or
        // per IP address without one.
        RateLimiter::for('authentication-two-factor', static function (Request $request): Limit {
            $challengedUserId = $request->hasSession() ? $request->session()->get('login.id') : null;

            return Limit::perMinute(5)->by(is_scalar($challengedUserId) ? 'user:'.$challengedUserId : 'ip:'.$request->ip());
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

    private function configureCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ResetTwoFactorAuthenticationCommand::class]);
        }
    }

    private function configureSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (! config('authentication.schedule')) {
                return;
            }

            $schedule->command('sanctum:prune-expired', ['--hours' => 24])->daily();
            $schedule->command('model:prune', ['--model' => [
                config()->string('authentication.login_codes.model', LoginCode::class),
                config()->string('authentication.two_factor.model', TrustedDevice::class),
                config()->string('authentication.audit.model', AuditLog::class),
            ]])->daily();
        });
    }

    /**
     * Let Nova's own screens handle two-factor authentication, when the app
     * runs Nova.
     */
    private function configureNova(): void
    {
        if (! class_exists(NovaCoreServiceProvider::class) || ! $this->app instanceof FoundationApplication || ! $this->app->providerIsLoaded(NovaCoreServiceProvider::class)) {
            return;
        }

        $this->app->make(NovaIntegration::class)->boot();
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
