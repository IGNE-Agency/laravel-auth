<?php

declare(strict_types=1);

namespace Igne\Auth\Audit\Models;

use Carbon\CarbonInterface;
use Igne\Auth\Audit\AuditEvent;
use Igne\Auth\Audit\Exceptions\AuditLogIsImmutableException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * An entry of the append-only security audit log.
 *
 * The actor is who acted, when known; the subject is the account the event
 * is about. It never holds credentials: a failed login keeps only the
 * submitted username ("metadata.username"), and a session or access token is
 * identified by the SHA-256 hash of its id ("credential_hash").
 *
 * Changing or deleting an entry through the model throws. "model:prune"
 * deletes the entries older than "authentication.audit.prune_after_days".
 *
 * @property string $id
 * @property CarbonInterface $occurred_at
 * @property AuditEvent $event
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $guard
 * @property string|null $channel
 * @property list<string>|null $auth_methods
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $credential_hash
 * @property string|null $request_id
 * @property array<string, mixed>|null $metadata
 */
#[Fillable(['occurred_at', 'event', 'guard', 'channel', 'auth_methods', 'ip_address', 'user_agent', 'credential_hash', 'request_id', 'metadata'])]
class AuditLog extends Model
{
    use HasUlids, MassPrunable;

    /**
     * Entries only have the moment the event occurred.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $table = 'audit_logs';

    /**
     * Get who acted, when known.
     *
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the account the event is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the entries older than "authentication.audit.prune_after_days".
     * Null keeps every entry.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        if (config('authentication.audit.prune_after_days') === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('occurred_at', '<', Date::now()->subDays(config()->integer('authentication.audit.prune_after_days')));
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw AuditLogIsImmutableException::forUpdate();
        });

        static::deleting(static function (): never {
            throw AuditLogIsImmutableException::forDelete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'event' => AuditEvent::class,
            'auth_methods' => 'array',
            'metadata' => 'array',
        ];
    }
}
