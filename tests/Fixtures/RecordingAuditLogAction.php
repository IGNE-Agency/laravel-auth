<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures;

use Igne\Auth\Audit\Actions\RecordAuditLogAction;
use Igne\Auth\Audit\AuditEntry;

/**
 * Keeps the audit entries in memory instead of writing them, as an app that
 * sends them to a SIEM would.
 */
final class RecordingAuditLogAction extends RecordAuditLogAction
{
    /**
     * @var list<AuditEntry>
     */
    public array $entries = [];

    public function execute(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
