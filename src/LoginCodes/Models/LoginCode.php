<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Models;

use Carbon\CarbonInterface;
use Igne\Auth\LoginCodes\Database\Factories\LoginCodeFactory;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\Date;
use SensitiveParameter;

/**
 * An emailed one-time code. Only an HMAC of the code is stored, keyed with the
 * app key, so a leaked table reveals no usable codes.
 *
 * @property string $id
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 * @property LoginCodePurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_address', 'user_agent'])]
#[Hidden(['code_hash'])]
#[UseFactory(LoginCodeFactory::class)]
class LoginCode extends Model
{
    /** @use HasFactory<LoginCodeFactory> */
    use HasFactory, HasUlids, MassPrunable;

    /**
     * Get the hash that is stored for the code: an HMAC-SHA256 keyed with the
     * current app key.
     */
    public static function hashFor(#[SensitiveParameter] string $code): string
    {
        return self::hashWithKey($code, self::currentKey());
    }

    /**
     * Get the user the code belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Determine whether the given code is this code. Codes hashed with a
     * previous app key ("app.previous_keys") still match, so rotating the key
     * doesn't break codes that are on their way.
     */
    public function matches(#[SensitiveParameter] string $code): bool
    {
        foreach ([self::currentKey(), ...self::previousKeys()] as $key) {
            if (hash_equals($this->code_hash, self::hashWithKey($code, $key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the codes that can be deleted: expired or used more than a day ago.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = Date::now()->subDay();

        return static::query()
            ->where('expires_at', '<', $cutoff)
            ->orWhere('consumed_at', '<', $cutoff);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => LoginCodePurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    private static function hashWithKey(#[SensitiveParameter] string $code, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $code, $key);
    }

    private static function currentKey(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new MissingAppKeyException;
        }

        return $key;
    }

    /**
     * @return list<string>
     */
    private static function previousKeys(): array
    {
        $keys = config('app.previous_keys', []);

        return array_values(array_filter(is_array($keys) ? $keys : [], static fn (mixed $key): bool => is_string($key) && $key !== ''));
    }
}
