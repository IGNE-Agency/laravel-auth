<?php

declare(strict_types=1);

namespace Igne\Auth\TwoFactor\Models;

use Carbon\CarbonInterface;
use Igne\Auth\TwoFactor\Database\Factories\TrustedDeviceFactory;
use Igne\Auth\TwoFactor\DeviceTrustLifetime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * A device, or an access token, a user verified two-factor authentication on.
 *
 * A remembered device holds the SHA-256 hash of the secret in its device
 * token ("{id}|{secret}"), which the client sends back as a cookie or header.
 * Trust that only lasts as long as an access token is bound to the token's id
 * and has no hash.
 *
 * @property string $id
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 * @property string|null $token_hash
 * @property string|null $access_token_id
 * @property string $name
 * @property string|null $ip_address
 * @property bool $remembered
 * @property CarbonInterface $verified_at
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['token_hash', 'access_token_id', 'name', 'ip_address', 'remembered', 'verified_at', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
#[UseFactory(TrustedDeviceFactory::class)]
class TrustedDevice extends Model
{
    /** @use HasFactory<TrustedDeviceFactory> */
    use HasFactory, HasUlids, MassPrunable;

    /**
     * Get the hash that is stored for the secret of a device token.
     */
    public static function hashSecret(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Get the user the device belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether the device is still trusted: it has not expired, and
     * its two-factor verification is at most 365 days old.
     */
    public function isActive(): bool
    {
        return ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->verified_at->greaterThan(Date::now()->subDays(DeviceTrustLifetime::MAX_DAYS));
    }

    /**
     * Determine whether the secret of a device token belongs to this device.
     */
    public function matchesSecret(#[SensitiveParameter] string $secret): bool
    {
        return $this->token_hash !== null && hash_equals($this->token_hash, self::hashSecret($secret));
    }

    /**
     * Get the devices that can be deleted: expired ones, and ones whose
     * verification is more than 365 days old.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('expires_at', '<', Date::now())
            ->orWhere('verified_at', '<', Date::now()->subDays(DeviceTrustLifetime::MAX_DAYS));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remembered' => 'boolean',
            'verified_at' => 'datetime',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
