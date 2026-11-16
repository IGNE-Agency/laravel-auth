<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Responses;

use Carbon\CarbonInterface;
use Igne\Auth\Http\Channel;
use Igne\Auth\Http\Responses\Concerns\IssuesAccessTokenAfterLogin;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\DeviceTrust;
use Igne\Auth\TwoFactor\Http\Middleware\PrepareTwoFactorStepUp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a passed two-factor challenge. Session mode gets Fortify's own
 * response; the device was trusted when the code was accepted.
 *
 * Token mode gets {token, expires_at, device_token} after a login challenge,
 * and {device_token} after a step-up of an access token whose trust lapsed.
 * The device token is null unless trust outlasts the access token.
 */
final class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    use IssuesAccessTokenAfterLogin;

    public function __construct(
        private readonly FortifyTwoFactorLoginResponse $sessionResponse,
        private readonly IssuesAccessTokens $tokens,
        private readonly StatefulGuard $guard,
        private readonly DeviceTrust $deviceTrust,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (Channel::of($request) === Channel::Session) {
            return $this->sessionResponse->toResponse($request);
        }

        $user = $this->guard->user();

        if (! $user instanceof Authenticatable) {
            throw new LogicException('A two-factor login response needs a logged-in user.');
        }

        $stepUpUser = $request->attributes->get(PrepareTwoFactorStepUp::USER_ATTRIBUTE);

        if ($stepUpUser instanceof Authenticatable) {
            return $this->stepUpResponse($request, $user, $stepUpUser);
        }

        $token = $this->issueAccessToken($request, $user);

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'expires_at' => $token->expiresAt?->toJSON(),
            'device_token' => $this->trust($request, $user, $token->id, $token->expiresAt),
        ]);
    }

    /**
     * Trust the access token the step-up was sent with again.
     */
    private function stepUpResponse(Request $request, Authenticatable $user, Authenticatable $stepUpUser): JsonResponse
    {
        $this->forgetSessionLogin();

        $accessTokenId = $this->tokens->currentTokenId($stepUpUser);

        return new JsonResponse([
            'device_token' => $accessTokenId === null ? null : $this->trust($request, $user, $accessTokenId),
        ]);
    }

    private function trust(Request $request, Authenticatable $user, string $accessTokenId, ?CarbonInterface $expiresAt = null): ?string
    {
        return $this->deviceTrust->trustAccessToken(
            $request,
            $user,
            $accessTokenId,
            $expiresAt,
            $request->boolean('remember_device'),
            $request->attributes->get(DeviceTrust::RECOVERY_CODE_ATTRIBUTE) === true,
        );
    }
}
