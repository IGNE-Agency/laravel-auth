<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Actions;

use Igne\Auth\Authentication\UserProviderResolver;
use Igne\Auth\Events\TwoFactorAuthenticationReset;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Resets the two-factor authentication of a user who lost their
 * authenticator, and logs them out everywhere, in one transaction:
 *
 * 1. disables two-factor authentication with Fortify's action;
 * 2. deletes all trusted devices;
 * 3. revokes all access tokens;
 * 4. deletes their database sessions (with the "database" session driver);
 * 5. cycles their remember token.
 *
 * Afterwards it fires TwoFactorAuthenticationReset, which sends the
 * configured notification.
 */
final class ResetTwoFactorAuthentication
{
    public function __construct(
        private readonly DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
        private readonly DeviceTrust $deviceTrust,
        private readonly IssuesAccessTokens $tokens,
        private readonly UserProviderResolver $users,
        private readonly DatabaseManager $database,
        private readonly Repository $config,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(TwoFactorAuthenticatable $user, ?Authenticatable $resetBy = null): void
    {
        $this->database->connection()->transaction(function () use ($user): void {
            ($this->disableTwoFactorAuthentication)($user);

            $this->deviceTrust->revokeAll($user, 'two_factor_reset');
            $this->tokens->revokeAll($user, 'two_factor_reset');
            $this->deleteDatabaseSessions($user);

            $this->users->resolve()->updateRememberToken($user, Str::random(60));
        });

        $this->events->dispatch(new TwoFactorAuthenticationReset($user, $resetBy, Date::now()));
    }

    private function deleteDatabaseSessions(TwoFactorAuthenticatable $user): void
    {
        if ($this->config->get('session.driver') !== 'database') {
            return;
        }

        $connection = $this->config->get('session.connection');

        $this->database->connection(is_string($connection) ? $connection : null)
            ->table($this->config->string('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
