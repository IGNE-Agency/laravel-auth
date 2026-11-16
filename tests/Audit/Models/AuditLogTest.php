<?php

declare(strict_types=1);

use Igne\Auth\Audit\AuditEvent;
use Igne\Auth\Audit\Exceptions\AuditLogIsImmutableException;
use Igne\Auth\Audit\Models\AuditLog;
use Illuminate\Support\Facades\Date;

/**
 * Write an audit log entry that occurred the given number of days ago.
 */
function auditLogFrom(int $daysAgo): AuditLog
{
    return AuditLog::query()->create([
        'occurred_at' => Date::now()->subDays($daysAgo),
        'event' => AuditEvent::LoginSucceeded,
    ]);
}

it('can\'t be changed', function (): void {
    $log = auditLogFrom(0);

    expect(fn (): bool => $log->update(['event' => AuditEvent::LoginFailed]))->toThrow(AuditLogIsImmutableException::class)
        ->and($log->fresh()?->event)->toBe(AuditEvent::LoginSucceeded);
});

it('can\'t be deleted', function (): void {
    $log = auditLogFrom(0);

    expect(fn (): ?bool => $log->delete())->toThrow(AuditLogIsImmutableException::class);
    $this->assertModelExists($log);
});

it('prunes the entries older than "prune_after_days"', function (?int $pruneAfterDays, array $keptDaysAgo): void {
    config(['authentication.audit.prune_after_days' => $pruneAfterDays]);
    $entries = collect([10, 40, 364, 366])->mapWithKeys(fn (int $daysAgo): array => [$daysAgo => auditLogFrom($daysAgo)]);

    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

    expect(AuditLog::query()->pluck('id')->sort()->values()->all())
        ->toBe($entries->only($keptDaysAgo)->pluck('id')->sort()->values()->all());
})->with([
    'the default year' => [365, [10, 40, 364]],
    'thirty days' => [30, [10]],
    'never, without a limit' => [null, [10, 40, 364, 366]],
]);
