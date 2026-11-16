<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor;

use Igne\Auth\TwoFactor\Exceptions\InvalidConfigurationException;
use Illuminate\Config\Repository;

/**
 * How long a device stays trusted after the user verified two-factor
 * authentication on it, from "authentication.two_factor.device_trust".
 *
 * Without "remember_device", trust lasts the session ("session") or a number
 * of days; with it, "remember_expires_in_days". No device stays trusted for
 * more than 365 days after its last verification.
 */
final readonly class DeviceTrustLifetime
{
    /**
     * The most days a device stays trusted after a verification.
     */
    public const int MAX_DAYS = 365;

    /**
     * @param  int|null  $expiresInDays  null when trust lasts the session
     */
    private function __construct(
        public ?int $expiresInDays,
        public int $rememberExpiresInDays,
        public bool $extendOnUse,
    ) {}

    /**
     * Read and validate the lifetime from the config.
     *
     * @throws InvalidConfigurationException
     */
    public static function fromConfig(Repository $config): self
    {
        $expiresInDays = $config->get('authentication.two_factor.device_trust.expires_in_days', 'session');

        return new self(
            $expiresInDays === 'session' ? null : self::days('expires_in_days', $expiresInDays, allowsSession: true),
            self::days('remember_expires_in_days', $config->get('authentication.two_factor.device_trust.remember_expires_in_days', 30), allowsSession: false),
            $config->boolean('authentication.two_factor.device_trust.extend_on_use', false),
        );
    }

    /**
     * Get the days a verification trusts the device for, or null when it
     * trusts the session (or access token) only.
     */
    public function daysFor(bool $remember): ?int
    {
        return $remember ? $this->rememberExpiresInDays : $this->expiresInDays;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function days(string $key, mixed $value, bool $allowsSession): int
    {
        $days = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };

        if ($days === null || $days < 1 || $days > self::MAX_DAYS) {
            throw InvalidConfigurationException::invalidDays($key, $value, self::MAX_DAYS, $allowsSession);
        }

        return $days;
    }
}
