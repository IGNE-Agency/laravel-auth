<?php

declare(strict_types=1);

namespace Igne\Auth\Authentication;

use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Igne\Auth\LoginCodes\Actions\ConsumeLoginCodeAction;
use Igne\Auth\LoginCodes\ConsumeLoginCodeResult;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use LogicException;
use SensitiveParameter;

/**
 * Confirms the identity of a logged-in user before a sensitive action. It is
 * registered with Fortify::confirmPasswordsUsing(), so Fortify's
 * confirm-password route and "password.confirm" middleware use it.
 *
 * The user confirms with the strongest factor they have, sent in the
 * "password" field: their password, else a code from their authenticator app
 * when they have two-factor authentication, else an emailed confirmation code.
 */
final class ConfirmIdentity
{
    public function __construct(
        private readonly UserProviderResolver $users,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly TwoFactorAuthenticationProvider $twoFactor,
    ) {}

    public function __invoke(mixed $user, #[SensitiveParameter] ?string $password = null): bool
    {
        if ($password === null || $password === '') {
            return false;
        }

        return match ($this->methodFor($user)) {
            AuthenticationMethod::Password => $user instanceof PasswordAuthenticatable && $this->users->resolve()->validateCredentials($user, ['password' => $password]),
            AuthenticationMethod::Totp => $user instanceof TwoFactorAuthenticatable && $this->verifyTwoFactorCode($user, $password),
            AuthenticationMethod::LoginCode => $user instanceof LoginCodeAuthenticatable && $this->consumeConfirmationCode($user, $password),
            default => false,
        };
    }

    /**
     * Get the factor the user confirms their identity with, or null when they
     * can't confirm it.
     */
    public function methodFor(mixed $user): ?AuthenticationMethod
    {
        if ($user instanceof PasswordAuthenticatable && $user->canAuthenticateWithPassword()) {
            return AuthenticationMethod::Password;
        }

        if ($user instanceof TwoFactorAuthenticatable && $user->hasEnabledTwoFactorAuthentication()) {
            return AuthenticationMethod::Totp;
        }

        if ($user instanceof LoginCodeAuthenticatable && $user->canAuthenticateWithLoginCode()) {
            return AuthenticationMethod::LoginCode;
        }

        return null;
    }

    /**
     * Verify a code from the user's authenticator app, with Fortify's provider
     * (which rejects a code that was used before).
     */
    private function verifyTwoFactorCode(TwoFactorAuthenticatable $user, #[SensitiveParameter] string $code): bool
    {
        $encryptedSecret = data_get($user, 'two_factor_secret');

        if (! is_string($encryptedSecret) || $encryptedSecret === '') {
            return false;
        }

        $secret = Fortify::currentEncrypter()->decrypt($encryptedSecret);

        return is_string($secret) && (bool) $this->twoFactor->verify($secret, $code);
    }

    private function consumeConfirmationCode(LoginCodeAuthenticatable $user, #[SensitiveParameter] string $code): bool
    {
        $actionClass = $this->config->string('authentication.login_codes.actions.consume_login_code');
        $action = $this->container->make($actionClass);

        if (! $action instanceof ConsumeLoginCodeAction) {
            throw new LogicException("The consume login code action [{$actionClass}] must extend [".ConsumeLoginCodeAction::class.'].');
        }

        return $action->execute($user, LoginCodePurpose::Confirm, $code) === ConsumeLoginCodeResult::Ok;
    }
}
