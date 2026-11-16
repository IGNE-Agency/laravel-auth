<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Igne\Auth\LoginCodes\Actions\ConsumeLoginCodeAction;
use Igne\Auth\LoginCodes\ConsumeLoginCodeResult;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;
use LogicException;
use SensitiveParameter;

/**
 * Checks the login credentials. It is registered with
 * Fortify::authenticateUsing(), so it replaces Fortify's own credential check
 * for the package routes and for Nova.
 *
 * The "password" field holds the user's password or an emailed login code.
 * The password is checked for users that can log in with one; when that does
 * not authenticate, a login code is consumed for users that can log in with
 * one.
 *
 * Fortify calls the callback twice for one login, so the outcome is memoized
 * on the request, and a login code is consumed only once.
 */
final class AuthenticateCredentials
{
    /**
     * The request attribute that holds the memoized outcome.
     */
    public const string USER_ATTRIBUTE = 'authentication.user';

    /**
     * The request attribute that lists the AuthenticationMethod cases the user
     * proved.
     */
    public const string METHODS_ATTRIBUTE = 'authentication.methods';

    public function __construct(
        private readonly UserProviderResolver $users,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly Timebox $timebox,
    ) {}

    public function __invoke(Request $request): ?Authenticatable
    {
        if ($request->attributes->has(self::USER_ATTRIBUTE)) {
            $memoizedUser = $request->attributes->get(self::USER_ATTRIBUTE);

            return $memoizedUser instanceof Authenticatable ? $memoizedUser : null;
        }

        $authenticated = $this->authenticate($request);

        $request->attributes->set(self::USER_ATTRIBUTE, $authenticated[0] ?? null);

        if ($authenticated !== null) {
            $request->attributes->set(self::METHODS_ATTRIBUTE, [$authenticated[1]]);
        }

        return $authenticated[0] ?? null;
    }

    /**
     * Find the user and check the submitted secret. The check takes a fixed
     * minimum time, like Laravel's own guard, so the response time does not
     * reveal whether the user exists.
     *
     * @return array{Authenticatable, AuthenticationMethod}|null
     */
    private function authenticate(Request $request): ?array
    {
        $username = $request->input(Fortify::username());
        $secret = $request->input('password');

        if (! is_string($username) || ! is_string($secret) || $secret === '') {
            return null;
        }

        return $this->timebox->call(function (Timebox $timebox) use ($username, $secret): ?array {
            $user = $this->users->findByUsername($username);

            if ($user === null) {
                return null;
            }

            $method = match (true) {
                $this->authenticateWithPassword($user, $secret) => AuthenticationMethod::Password,
                $this->authenticateWithLoginCode($user, $secret) => AuthenticationMethod::LoginCode,
                default => null,
            };

            if ($method === null) {
                return null;
            }

            $timebox->returnEarly();

            return [$user, $method];
        }, $this->config->integer('auth.timebox_duration', 200000));
    }

    /**
     * Check the password of a user that can log in with one, and rehash it
     * when its hash is outdated.
     */
    private function authenticateWithPassword(Authenticatable $user, #[SensitiveParameter] string $password): bool
    {
        if (! $user instanceof PasswordAuthenticatable || ! $user->canAuthenticateWithPassword()) {
            return false;
        }

        $provider = $this->users->resolve();
        $credentials = ['password' => $password];

        if (! $provider->validateCredentials($user, $credentials)) {
            return false;
        }

        if ($this->config->get('hashing.rehash_on_login', true)) {
            $provider->rehashPasswordIfRequired($user, $credentials);
        }

        return true;
    }

    /**
     * Consume a login code of a user that can log in with one.
     */
    private function authenticateWithLoginCode(Authenticatable $user, #[SensitiveParameter] string $code): bool
    {
        if (! $user instanceof LoginCodeAuthenticatable || ! $user->canAuthenticateWithLoginCode()) {
            return false;
        }

        $actionClass = $this->config->string('authentication.login_codes.actions.consume_login_code');
        $action = $this->container->make($actionClass);

        if (! $action instanceof ConsumeLoginCodeAction) {
            throw new LogicException("The consume login code action [{$actionClass}] must extend [".ConsumeLoginCodeAction::class.'].');
        }

        return $action->execute($user, LoginCodePurpose::Login, $code) === ConsumeLoginCodeResult::Ok;
    }
}
