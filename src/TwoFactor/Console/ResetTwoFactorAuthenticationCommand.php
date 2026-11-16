<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Console;

use Igne\Auth\Authentication\UserProviderResolver;
use Igne\Auth\TwoFactor\Actions\ResetTwoFactorAuthentication;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Illuminate\Console\Command;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Resets the two-factor authentication of a user who lost their
 * authenticator, and logs them out everywhere.
 */
#[AsCommand(name: 'authentication:two-factor:reset')]
final class ResetTwoFactorAuthenticationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'authentication:two-factor:reset {user : The id or the username (email) of the user}';

    /**
     * @var string
     */
    protected $description = 'Reset the two-factor authentication of a user and log them out everywhere';

    public function handle(UserProviderResolver $users, ResetTwoFactorAuthentication $reset): int
    {
        $identifier = $this->argument('user');

        if (! is_string($identifier)) {
            throw new LogicException('The [user] argument must be a single value.');
        }

        $user = $users->findByUsername($identifier) ?? $users->resolve()->retrieveById($identifier);

        if (! $user instanceof TwoFactorAuthenticatable) {
            $this->components->error("No user with two-factor authentication matches [{$identifier}].");

            return self::FAILURE;
        }

        $reset($user);

        $this->components->info("The two-factor authentication of [{$identifier}] was reset.");

        return self::SUCCESS;
    }
}
