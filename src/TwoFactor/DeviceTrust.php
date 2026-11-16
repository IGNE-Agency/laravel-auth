<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Igne\Auth\Events\DeviceTrusted;
use Igne\Auth\Events\DeviceTrustRevoked;
use Igne\Auth\Http\DeviceName;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieJar;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Decides which devices skip the two-factor challenge, and trusts a device
 * once the user verified two-factor authentication on it.
 *
 * Trust lives in one of three places:
 *
 * - session mode, trust for the session: a flag in the cookie session;
 * - token mode, trust for the access token: a trusted device row bound to the
 *   token, deleted with it;
 * - otherwise, a remembered device: a trusted device row whose device token
 *   ("{id}|{secret}", of which only the secret's SHA-256 is stored) the client
 *   sends back as the "two_factor_device" cookie or the "X-Two-Factor-Device"
 *   header. A token client's device row is also bound to its access token, so
 *   its requests don't need the header.
 */
final class DeviceTrust
{
    /**
     * The session key of the flag that trusts a cookie session.
     */
    public const string SESSION_KEY = 'authentication.two_factor_trust';

    /**
     * The request attribute that marks a request in which the user used a
     * recovery code. Trust from a recovery code is never remembered.
     */
    public const string RECOVERY_CODE_ATTRIBUTE = 'authentication.two_factor.recovery_code_used';

    /**
     * How often, at most, using a device updates its "last_used_at" and
     * extends its expiry.
     */
    private const int USE_RECORDING_INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly Repository $config,
        private readonly CookieJar $cookies,
        private readonly IssuesAccessTokens $tokens,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Determine whether the request comes from a session, access token or
     * device the user verified two-factor authentication on.
     */
    public function isTrusted(Request $request, TwoFactorAuthenticatable $user): bool
    {
        if ($this->sessionIsTrusted($request, $user)) {
            return true;
        }

        $device = $this->rememberedDevice($request, $user) ?? $this->deviceOfCurrentAccessToken($user);

        if ($device === null) {
            return false;
        }

        $this->recordUse($device);

        return true;
    }

    /**
     * Determine whether the request comes from a remembered device of the
     * user, which skips the two-factor challenge at login.
     */
    public function isTrustedForLogin(Request $request, TwoFactorAuthenticatable $user): bool
    {
        $device = $this->rememberedDevice($request, $user);

        if ($device === null) {
            return false;
        }

        $this->recordUse($device);

        return true;
    }

    /**
     * Trust the cookie session of a session-mode request after the user
     * verified two-factor authentication: with a flag in the session, or with
     * a device row and its cookie when trust outlasts the session. Users
     * without the TwoFactorAuthenticatable contract get no trust.
     */
    public function trustSession(Request $request, Authenticatable $user, bool $remember = false, bool $recoveryCodeUsed = false): void
    {
        if (! $user instanceof TwoFactorAuthenticatable) {
            return;
        }

        $days = $recoveryCodeUsed ? null : $this->lifetime()->daysFor($remember);

        if ($days === null) {
            $request->session()->put(self::SESSION_KEY, [
                'user' => (string) $user->getAuthIdentifier(),
                'enrollment' => $this->enrollmentOf($user),
                'verified_at' => Date::now()->getTimestamp(),
            ]);

            $this->events->dispatch(new DeviceTrusted($user, null, false, null));

            return;
        }

        $deviceToken = $this->createDevice($request, $user, $remember, $days);

        $this->cookies->queue($this->cookies->make($this->cookieName(), $deviceToken, $days * 24 * 60));
    }

    /**
     * Trust an access token of a token-mode request after the user verified
     * two-factor authentication. When trust outlasts the token, the device
     * gets a device token, which is returned. Users without the
     * TwoFactorAuthenticatable contract get no trust.
     *
     * @return string|null the device token for the "X-Two-Factor-Device" header, if one was made
     */
    public function trustAccessToken(
        Request $request,
        Authenticatable $user,
        string $accessTokenId,
        ?CarbonInterface $accessTokenExpiresAt = null,
        bool $remember = false,
        bool $recoveryCodeUsed = false,
    ): ?string {
        if (! $user instanceof TwoFactorAuthenticatable) {
            return null;
        }

        $days = $recoveryCodeUsed ? null : $this->lifetime()->daysFor($remember);

        $user->trustedDevices()->where('access_token_id', $accessTokenId)->whereNull('token_hash')->delete();

        if ($days !== null) {
            return $this->createDevice($request, $user, $remember, $days, $accessTokenId);
        }

        $device = $this->newDevice($request, $user, false, $accessTokenExpiresAt);
        $device->access_token_id = $accessTokenId;
        $device->save();

        $this->events->dispatch(new DeviceTrusted($user, $device->id, false, $device->expires_at));

        return null;
    }

    /**
     * Bind the remembered device the request comes from to a new access token,
     * after a login that skipped the challenge on it. The token's requests are
     * then trusted without the device header.
     */
    public function bindRememberedDevice(Request $request, Authenticatable $user, string $accessTokenId): void
    {
        if (! $user instanceof TwoFactorAuthenticatable) {
            return;
        }

        $this->rememberedDevice($request, $user)?->forceFill(['access_token_id' => $accessTokenId])->save();
    }

    /**
     * Revoke the trusted devices of the user. With a request, the session,
     * access token and device that request comes from stay trusted.
     */
    public function revokeAll(Authenticatable $user, string $reason, ?Request $keepCurrentOf = null): void
    {
        if (! $user instanceof TwoFactorAuthenticatable) {
            return;
        }

        $currentDeviceIds = $keepCurrentOf === null ? [] : array_values(array_filter([
            $this->rememberedDevice($keepCurrentOf, $user)?->id,
            $this->deviceOfCurrentAccessToken($user)?->id,
        ]));

        $user->trustedDevices()->whereKeyNot($currentDeviceIds)->get()->each(function (TrustedDevice $device) use ($user, $reason): void {
            $device->delete();

            $this->events->dispatch(new DeviceTrustRevoked($user, $device->id, $reason));
        });
    }

    /**
     * Forget the trust that was bound to a deleted access token: delete the
     * trust that only lasted as long as the token, and unbind remembered
     * devices.
     */
    public function forgetAccessToken(string $accessTokenId): void
    {
        $model = $this->model();

        $model::query()->where('access_token_id', $accessTokenId)->whereNull('token_hash')->delete();
        $model::query()->where('access_token_id', $accessTokenId)->update(['access_token_id' => null]);
    }

    /**
     * Get the configured lifetime of the trust.
     */
    public function lifetime(): DeviceTrustLifetime
    {
        return DeviceTrustLifetime::fromConfig($this->config);
    }

    /**
     * Determine whether the cookie session carries a flag for the user's
     * current two-factor enrollment, verified at most 365 days ago.
     */
    private function sessionIsTrusted(Request $request, TwoFactorAuthenticatable $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $trust = $request->session()->get(self::SESSION_KEY);

        return is_array($trust)
            && ($trust['user'] ?? null) === (string) $user->getAuthIdentifier()
            && is_string($trust['enrollment'] ?? null)
            && hash_equals($trust['enrollment'], $this->enrollmentOf($user))
            && is_int($trust['verified_at'] ?? null)
            && $trust['verified_at'] > Date::now()->subDays(DeviceTrustLifetime::MAX_DAYS)->getTimestamp();
    }

    /**
     * Get the active remembered device of the user whose device token the
     * request sends, as a cookie or a header.
     */
    private function rememberedDevice(Request $request, TwoFactorAuthenticatable $user): ?TrustedDevice
    {
        foreach ([$request->cookie($this->cookieName()), $request->header($this->headerName())] as $deviceToken) {
            if (! is_string($deviceToken) || ! str_contains($deviceToken, '|')) {
                continue;
            }

            [$id, $secret] = explode('|', $deviceToken, 2);

            if (! Str::isUlid($id)) {
                continue;
            }

            $device = $user->trustedDevices()->whereKey($id)->first();

            if ($device instanceof TrustedDevice && $device->matchesSecret($secret) && $device->isActive()) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Get the active trust bound to the access token the request was
     * authenticated with.
     */
    private function deviceOfCurrentAccessToken(TwoFactorAuthenticatable $user): ?TrustedDevice
    {
        $accessTokenId = $this->tokens->currentTokenId($user);

        if ($accessTokenId === null) {
            return null;
        }

        return $user->trustedDevices()
            ->where('access_token_id', $accessTokenId)
            ->get()
            ->first(static fn (TrustedDevice $device): bool => $device->isActive());
    }

    /**
     * Record that the device was used, at most once a minute, and extend its
     * expiry when "extend_on_use" is on, up to 365 days after its verification.
     */
    private function recordUse(TrustedDevice $device): void
    {
        if ($device->last_used_at !== null && $device->last_used_at->greaterThan(Date::now()->subSeconds(self::USE_RECORDING_INTERVAL_SECONDS))) {
            return;
        }

        $device->last_used_at = Date::now();

        $lifetime = $this->lifetime();
        $days = $device->token_hash === null ? null : $lifetime->daysFor($device->remembered);

        if ($lifetime->extendOnUse && $days !== null) {
            $extendedExpiry = Date::now()->addDays($days);
            $latestExpiry = $device->verified_at->copy()->addDays(DeviceTrustLifetime::MAX_DAYS);

            $device->expires_at = $extendedExpiry->lessThan($latestExpiry) ? $extendedExpiry : $latestExpiry;
        }

        $device->save();
    }

    /**
     * Create a device row with a device token.
     *
     * @return string the device token: "{id}|{secret}"
     */
    private function createDevice(Request $request, TwoFactorAuthenticatable $user, bool $remember, int $days, ?string $accessTokenId = null): string
    {
        $secret = Str::random(40);

        $device = $this->newDevice($request, $user, $remember, Date::now()->addDays($days));
        $device->token_hash = TrustedDevice::hashSecret($secret);
        $device->access_token_id = $accessTokenId;
        $device->save();

        $this->events->dispatch(new DeviceTrusted($user, $device->id, $remember, $device->expires_at));

        return $device->id.'|'.$secret;
    }

    private function newDevice(Request $request, TwoFactorAuthenticatable $user, bool $remembered, ?CarbonInterface $expiresAt): TrustedDevice
    {
        /** @var TrustedDevice */
        return $user->trustedDevices()->make([
            'name' => DeviceName::of($request),
            'ip_address' => $request->ip(),
            'remembered' => $remembered,
            'verified_at' => Date::now(),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Get a fingerprint of the user's current two-factor enrollment, so a
     * session flag stops counting once two-factor authentication is reset and
     * set up again.
     */
    private function enrollmentOf(TwoFactorAuthenticatable $user): string
    {
        $confirmedAt = data_get($user, 'two_factor_confirmed_at');
        $secret = data_get($user, 'two_factor_secret');

        $marker = match (true) {
            $confirmedAt instanceof DateTimeInterface => 'confirmed:'.$confirmedAt->format('Y-m-d H:i:s'),
            is_string($confirmedAt) => 'confirmed:'.$confirmedAt,
            default => 'secret:'.(is_string($secret) ? $secret : ''),
        };

        return hash('sha256', $marker);
    }

    /**
     * @return class-string<TrustedDevice>
     */
    private function model(): string
    {
        /** @var class-string<TrustedDevice> */
        return $this->config->get('authentication.two_factor.model', TrustedDevice::class);
    }

    private function cookieName(): string
    {
        return (string) $this->config->get('authentication.two_factor.device_trust.cookie', 'two_factor_device');
    }

    private function headerName(): string
    {
        return (string) $this->config->get('authentication.two_factor.device_trust.header', 'X-Two-Factor-Device');
    }
}
