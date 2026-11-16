<?php

declare(strict_types=1);

namespace Igne\Auth\Audit\Actions;

use Igne\Auth\Audit\AuditEntry;
use Igne\Auth\Audit\Models\AuditLog;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit entry to the audit log model configured in
 * "authentication.audit.model". Extend it and set
 * "authentication.audit.actions.record_audit_log" to send entries elsewhere
 * too, such as to a SIEM.
 */
class RecordAuditLogAction
{
    public function __construct(private readonly Repository $config) {}

    public function execute(AuditEntry $entry): void
    {
        /** @var class-string<AuditLog> $model */
        $model = $this->config->get('authentication.audit.model', AuditLog::class);

        $log = new $model([
            'occurred_at' => $entry->occurredAt,
            'event' => $entry->event,
            'guard' => $entry->guard,
            'channel' => $entry->channel,
            'auth_methods' => $entry->authMethods,
            'ip_address' => $entry->ipAddress,
            'user_agent' => $entry->userAgent,
            'credential_hash' => $entry->credentialHash,
            'request_id' => $entry->requestId,
            'metadata' => $entry->metadata === [] ? null : $entry->metadata,
        ]);

        if ($entry->actor instanceof Model) {
            $log->actor()->associate($entry->actor);
        }

        if ($entry->subject instanceof Model) {
            $log->subject()->associate($entry->subject);
        }

        $log->save();
    }
}
