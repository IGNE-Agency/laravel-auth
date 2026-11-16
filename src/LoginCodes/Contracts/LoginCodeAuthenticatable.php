<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Contracts;

use Igne\Auth\LoginCodes\Models\LoginCode;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A user that can log in with an emailed code. The LoginCodeAuthenticatable
 * trait gives the default implementation. The model must be notifiable to
 * receive the codes.
 */
interface LoginCodeAuthenticatable extends Authenticatable
{
    /**
     * Determine whether the user can log in with an emailed code right now.
     */
    public function canAuthenticateWithLoginCode(): bool;

    /**
     * Get the emailed codes of the user.
     *
     * @return MorphMany<LoginCode, covariant Model>
     */
    public function loginCodes(): MorphMany;
}
