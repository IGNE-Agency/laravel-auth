<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Listeners;

use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Http\Channel;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\DeviceTrust;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Trusts the device a user just verified two-factor authentication on: with a
 * valid challenge code, or by confirming their two-factor setup.
 *
 * Session mode is trusted right away, which also covers Nova's challenge. In
 * token mode a challenge has no access token yet, so the package's two-factor
 * login response trusts the token it issues; confirming the setup trusts the
 * token the request was sent with.
 */
final class IssueDeviceTrust
{
    public function __construct(
        private readonly Container $container,
        private readonly DeviceTrust $deviceTrust,
        private readonly IssuesAccessTokens $tokens,
    ) {}

    public function handle(ValidTwoFactorAuthenticationCodeProvided|TwoFactorAuthenticationConfirmed $event): void
    {
        $user = $event->user;

        if (! $user instanceof Authenticatable) {
            return;
        }

        $request = $this->container->make(Request::class);
        $recoveryCodeUsed = $request->attributes->get(DeviceTrust::RECOVERY_CODE_ATTRIBUTE) === true;

        $this->recordMethod($request, $recoveryCodeUsed ? AuthenticationMethod::RecoveryCode : AuthenticationMethod::Totp);

        if (Channel::of($request) === Channel::Session) {
            $remember = $event instanceof ValidTwoFactorAuthenticationCodeProvided && $request->boolean('remember_device');

            $this->deviceTrust->trustSession($request, $user, $remember, $recoveryCodeUsed);

            return;
        }

        if ($event instanceof TwoFactorAuthenticationConfirmed) {
            $this->trustCurrentAccessToken($request, $user);
        }
    }

    /**
     * Trust the access token of the user who just confirmed their setup.
     */
    private function trustCurrentAccessToken(Request $request, Authenticatable $user): void
    {
        $authenticated = $request->user();
        $accessTokenId = $authenticated instanceof Authenticatable ? $this->tokens->currentTokenId($authenticated) : null;

        if ($accessTokenId !== null) {
            $this->deviceTrust->trustAccessToken($request, $user, $accessTokenId);
        }
    }

    /**
     * Add the factor to the "authentication.methods" request attribute.
     */
    private function recordMethod(Request $request, AuthenticationMethod $method): void
    {
        $methods = $request->attributes->get(AuthenticateCredentials::METHODS_ATTRIBUTE, []);

        $request->attributes->set(AuthenticateCredentials::METHODS_ATTRIBUTE, [...(is_array($methods) ? $methods : []), $method]);
    }
}
