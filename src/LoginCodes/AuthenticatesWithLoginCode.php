<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes;

use Igne\Auth\LoginCodes\Models\LoginCode;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The default implementation of the LoginCodeAuthenticatable contract.
 */
trait AuthenticatesWithLoginCode
{
    /**
     * Determine whether the user can log in with an emailed code right now.
     * Override it to limit codes to some users, e.g. to users without a
     * password.
     */
    public function canAuthenticateWithLoginCode(): bool
    {
        return true;
    }

    /**
     * Get the emailed codes of the user, as the model configured in
     * "authentication.login_codes.model".
     *
     * @return MorphMany<LoginCode, $this>
     */
    public function loginCodes(): MorphMany
    {
        /** @var class-string<LoginCode> $model */
        $model = config('authentication.login_codes.model', LoginCode::class);

        return $this->morphMany($model, 'authenticatable');
    }
}
