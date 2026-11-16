<?php

declare(strict_types=1);

namespace Igne\Auth\Audit\Exceptions;

use LogicException;

/**
 * The audit log is append-only: its entries can't be changed or deleted
 * through the model. Old entries are pruned with "model:prune".
 */
final class AuditLogIsImmutableException extends LogicException
{
    public static function forUpdate(): self
    {
        return new self('Audit log entries can\'t be changed.');
    }

    public static function forDelete(): self
    {
        return new self('Audit log entries can\'t be deleted; old entries are pruned after "authentication.audit.prune_after_days".');
    }
}
