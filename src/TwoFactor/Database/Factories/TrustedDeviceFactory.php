<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Database\Factories;

use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * Makes remembered devices. Give the user with ->for($user, 'authenticatable').
 *
 * @extends Factory<TrustedDevice>
 */
final class TrustedDeviceFactory extends Factory
{
    protected $model = TrustedDevice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => TrustedDevice::hashSecret(fake()->sha1()),
            'name' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
            'remembered' => true,
            'verified_at' => Date::now(),
            'expires_at' => Date::now()->addDays(30),
        ];
    }

    /**
     * A device whose device token has the given secret.
     */
    public function withSecret(string $secret): static
    {
        return $this->state(['token_hash' => TrustedDevice::hashSecret($secret)]);
    }

    /**
     * Trust that is bound to an access token instead of a device token.
     */
    public function forAccessToken(string $accessTokenId): static
    {
        return $this->state([
            'token_hash' => null,
            'access_token_id' => $accessTokenId,
            'remembered' => false,
            'expires_at' => null,
        ]);
    }

    /**
     * A device whose trust has expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => Date::now()->subSecond()]);
    }
}
