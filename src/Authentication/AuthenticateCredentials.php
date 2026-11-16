<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * Checks the login credentials. It is registered with
 * Fortify::authenticateUsing(), so it replaces Fortify's own credential check
 * for the package routes and for Nova.
 *
 * Fortify calls the callback twice for one login, so the outcome is memoized
 * on the request.
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
        private readonly AuthManager $auth,
        private readonly Repository $config,
        private readonly Timebox $timebox,
    ) {}

    public function __invoke(Request $request): ?Authenticatable
    {
        if ($request->attributes->has(self::USER_ATTRIBUTE)) {
            /** @var Authenticatable|null */
            return $request->attributes->get(self::USER_ATTRIBUTE);
        }

        $user = $this->authenticateWithPassword($request);

        $request->attributes->set(self::USER_ATTRIBUTE, $user);

        if ($user !== null) {
            $request->attributes->set(self::METHODS_ATTRIBUTE, [AuthenticationMethod::Password]);
        }

        return $user;
    }

    /**
     * Check the password of a user that can log in with one. The check takes a
     * fixed minimum time, like Laravel's own guard, so the response time does
     * not reveal whether the user exists.
     */
    private function authenticateWithPassword(Request $request): ?Authenticatable
    {
        $username = $request->input(Fortify::username());
        $password = $request->input('password');

        if (! is_string($username) || ! is_string($password) || $password === '') {
            return null;
        }

        $provider = $this->userProvider();

        /** @var Authenticatable|null */
        return $this->timebox->call(function (Timebox $timebox) use ($provider, $username, $password): ?Authenticatable {
            $user = $provider->retrieveByCredentials([Fortify::username() => $username]);

            if (! $user instanceof PasswordAuthenticatable || ! $user->canAuthenticateWithPassword()) {
                return null;
            }

            $credentials = ['password' => $password];

            if (! $provider->validateCredentials($user, $credentials)) {
                return null;
            }

            if ($this->config->get('hashing.rehash_on_login', true)) {
                $provider->rehashPasswordIfRequired($user, $credentials);
            }

            $timebox->returnEarly();

            return $user;
        }, (int) $this->config->get('auth.timebox_duration', 200000));
    }

    /**
     * Get the user provider of the guard Fortify logs in with.
     */
    private function userProvider(): UserProvider
    {
        $guard = $this->config->get('fortify.guard', 'web');
        $provider = $this->auth->createUserProvider($this->config->get("auth.guards.{$guard}.provider"));

        if ($provider === null) {
            throw new LogicException("The [{$guard}] guard has no user provider.");
        }

        return $provider;
    }
}
