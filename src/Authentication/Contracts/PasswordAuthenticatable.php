<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A user that can log in with a password. The AuthenticatesWithPassword trait
 * gives the default implementation.
 */
interface PasswordAuthenticatable extends Authenticatable
{
    /**
     * Determine whether the user can log in with a password right now.
     */
    public function canAuthenticateWithPassword(): bool;
}
