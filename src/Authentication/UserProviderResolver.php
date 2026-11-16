<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * Finds users through the user provider of the guard Fortify logs in with.
 */
final class UserProviderResolver
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Repository $config,
    ) {}

    /**
     * Get the user provider of the guard Fortify logs in with.
     */
    public function resolve(): UserProvider
    {
        $guard = $this->config->get('fortify.guard', 'web');
        $provider = $this->auth->createUserProvider($this->config->get("auth.guards.{$guard}.provider"));

        if ($provider === null) {
            throw new LogicException("The [{$guard}] guard has no user provider.");
        }

        return $provider;
    }

    /**
     * Find the user with the given username (Fortify's "username" field).
     */
    public function findByUsername(string $username): ?Authenticatable
    {
        return $this->resolve()->retrieveByCredentials([Fortify::username() => $username]);
    }
}
