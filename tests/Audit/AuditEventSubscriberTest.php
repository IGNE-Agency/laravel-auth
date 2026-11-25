<?php

declare(strict_types=1);

use Igne\Auth\Audit\AuditEntry;
use Igne\Auth\Audit\AuditEvent;
use Igne\Auth\Audit\Models\AuditLog;
use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Events\DeviceTrusted;
use Igne\Auth\Events\DeviceTrustRevoked;
use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\Events\SessionRevoked;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\Tests\Fixtures\RecordingAuditLogAction;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Actions\ResetTwoFactorAuthentication;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Get the only audit log entry of the event.
 */
function auditEntry(AuditEvent $event): AuditLog
{
    return AuditLog::query()->where('event', $event->value)->sole();
}

/**
 * Get the events of the audit log entries, oldest first.
 *
 * @return list<string>
 */
function auditEvents(): array
{
    return AuditLog::query()->orderBy('id')->get()->map(fn (AuditLog $log): string => $log->event->value)->all();
}

it('records a token client\'s password login with the context of the request', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();

    $this->loginForToken($user);

    $login = auditEntry(AuditEvent::LoginSucceeded);
    $token = auditEntry(AuditEvent::TokenCreated);
    expect($login->subject?->is($user))->toBeTrue()
        ->and($login->actor?->is($user))->toBeTrue()
        ->and($login->only(['guard', 'channel', 'auth_methods', 'ip_address', 'user_agent', 'credential_hash', 'metadata']))->toBe([
            'guard' => 'web',
            'channel' => 'token',
            'auth_methods' => ['password'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'credential_hash' => null,
            'metadata' => ['remember' => false],
        ])
        ->and($login->request_id)->not->toBeNull()
        ->and($token->request_id)->toBe($login->request_id)
        ->and($token->credential_hash)->toBe(hash('sha256', (string) $user->tokens()->sole()->getKey()))
        ->and($token->metadata)->toBe(['name' => 'Symfony', 'expires_at' => $user->tokens()->sole()->expires_at?->toJSON()]);
});

it('records an SPA login in session mode', function (): void {
    $user = User::factory()->create();

    $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

    expect(auditEntry(AuditEvent::LoginSucceeded)->channel)->toBe('session');
});

it('records the requested login code and the login with it', function (): void {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();
    $this->postJson('/api/auth/login-code', ['email' => $user->email])->assertAccepted();

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $this->lastLoginCodeNotification($user)->code])->assertOk();

    $requested = auditEntry(AuditEvent::LoginCodeRequested);
    expect($requested->actor)->toBeNull()
        ->and($requested->subject?->is($user))->toBeTrue()
        ->and($requested->metadata)->toBe(['purpose' => 'login'])
        ->and(auditEntry(AuditEvent::LoginSucceeded)->auth_methods)->toBe(['login_code']);
});

it('records the two-factor challenge, a wrong code and the login that passes it', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $this->passChallenge($this->loginForChallenge($user), ['code' => '000000'])->assertUnprocessable();
    $this->passChallenge($this->loginForChallenge($user), ['code' => $this->twoFactorCode($user)])->assertOk();

    $challenged = AuditLog::query()->where('event', AuditEvent::TwoFactorChallenged->value)->get();
    expect(auditEvents())->toBe([
        'two_factor.challenged',
        'two_factor.failed',
        'two_factor.challenged',
        'two_factor.succeeded',
        'login.succeeded',
        'token.created',
        'device.trusted',
    ])
        ->and($challenged->pluck('auth_methods')->all())->toBe([['password'], ['password']])
        ->and($challenged->pluck('channel')->all())->toBe(['token', 'token'])
        ->and(auditEntry(AuditEvent::TwoFactorFailed)->subject?->is($user))->toBeTrue()
        ->and(auditEntry(AuditEvent::TwoFactorSucceeded)->auth_methods)->toBe(['totp'])
        ->and(auditEntry(AuditEvent::LoginSucceeded)->auth_methods)->toBe(['totp'])
        ->and(auditEntry(AuditEvent::DeviceTrusted)->actor?->is($user))->toBeTrue();
});

it('records a login with a recovery code', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $this->passChallenge($this->loginForChallenge($user), ['recovery_code' => $this->recoveryCode($user)])->assertOk();

    expect(auditEntry(AuditEvent::TwoFactorRecoveryCodeUsed)->subject?->is($user))->toBeTrue()
        ->and(auditEntry(AuditEvent::TwoFactorSucceeded)->auth_methods)->toBe(['recovery_code']);
});

it('records a failed login with the submitted username only', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'not-the-secret-password'])->assertUnprocessable();

    $failed = auditEntry(AuditEvent::LoginFailed);
    expect($failed->metadata)->toBe(['username' => $user->email])
        ->and($failed->actor)->toBeNull()
        ->and((string) json_encode($failed->getAttributes()))->not->toContain('not-the-secret-password');
});

it('records a login lockout with the submitted username', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    expect(auditEntry(AuditEvent::LoginLockout)->metadata)->toBe(['username' => $user->email]);
});

it('skips a login restored from a "remember me" cookie', function (): void {
    Route::middleware('web')->get('remembered-area', fn (): array => ['id' => Auth::id()]);
    $user = User::factory()->create(['remember_token' => Str::random(60)]);

    $response = $this->withCookie(Auth::guard('web')->getRecallerName(), $user->id.'|'.$user->remember_token.'|'.$user->password)
        ->get('/remembered-area');

    $response->assertOk()->assertJson(['id' => $user->id]);
    expect(AuditLog::query()->count())->toBe(0);
});

it('records a token client\'s logout from its revoked access token', function (): void {
    $user = User::factory()->create();
    $token = $this->loginForToken($user);
    $tokenId = (string) $user->tokens()->sole()->getKey();

    $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

    $logout = auditEntry(AuditEvent::Logout);
    expect($logout->actor?->is($user))->toBeTrue()
        ->and($logout->channel)->toBe('token')
        ->and($logout->credential_hash)->toBe(hash('sha256', $tokenId))
        ->and(AuditLog::query()->where('event', AuditEvent::TokenRevoked->value)->exists())->toBeFalse();
});

it('records a logout of a cookie session with the hash of the session', function (): void {
    $user = User::factory()->create();
    $sessionId = Str::random(40);

    $this->actingAs($user, 'web')
        ->fromSpa()
        ->withCredentials()
        ->withCookie(config('session.cookie'), $sessionId)
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    $logout = auditEntry(AuditEvent::Logout);
    expect($logout->subject?->is($user))->toBeTrue()
        ->and($logout->channel)->toBe('session')
        ->and($logout->credential_hash)->toBe(hash('sha256', $sessionId));
});

it('ignores a logout without a user', function (): void {
    event(new Logout('web', null));

    expect(AuditLog::query()->count())->toBe(0);
});

it('records the user\'s own security changes with them as the actor', function (Closure $makeEvent, AuditEvent $expected): void {
    $user = User::factory()->create();

    event($makeEvent($user));

    $entry = auditEntry($expected);
    expect($entry->subject?->is($user))->toBeTrue()
        ->and($entry->actor?->is($user))->toBeTrue()
        ->and($entry->channel)->toBeNull();
})->with([
    'a challenge' => [fn (User $user): object => new TwoFactorAuthenticationChallenged($user), AuditEvent::TwoFactorChallenged],
    'a wrong code' => [fn (User $user): object => new TwoFactorAuthenticationFailed($user), AuditEvent::TwoFactorFailed],
    'a valid code' => [fn (User $user): object => new ValidTwoFactorAuthenticationCodeProvided($user), AuditEvent::TwoFactorSucceeded],
    'a used recovery code' => [fn (User $user): object => new RecoveryCodeReplaced($user, 'recovery-code'), AuditEvent::TwoFactorRecoveryCodeUsed],
    'the setup' => [fn (User $user): object => new TwoFactorAuthenticationEnabled($user), AuditEvent::TwoFactorEnabled],
    'the confirmed setup' => [fn (User $user): object => new TwoFactorAuthenticationConfirmed($user), AuditEvent::TwoFactorConfirmed],
    'disabling it' => [fn (User $user): object => new TwoFactorAuthenticationDisabled($user), AuditEvent::TwoFactorDisabled],
    'new recovery codes' => [fn (User $user): object => new RecoveryCodesGenerated($user), AuditEvent::TwoFactorRecoveryCodesGenerated],
    'a changed password' => [fn (User $user): object => new PasswordUpdatedViaController($user), AuditEvent::PasswordChanged],
    'a reset password' => [fn (User $user): object => new PasswordReset($user), AuditEvent::PasswordReset],
    'a trusted device' => [fn (User $user): object => new DeviceTrusted($user, null, false, null), AuditEvent::DeviceTrusted],
]);

it('records what happened to an account without an actor outside a request', function (Closure $makeEvent, AuditEvent $expected, array $metadata): void {
    $user = User::factory()->create();

    event($makeEvent($user));

    $entry = auditEntry($expected);
    expect($entry->subject?->is($user))->toBeTrue()
        ->and($entry->actor)->toBeNull()
        ->and($entry->metadata)->toBe($metadata === [] ? null : $metadata);
})->with([
    'a requested reset link' => [fn (User $user): object => new PasswordResetLinkSent($user), AuditEvent::PasswordResetRequested, []],
    'a requested login code' => [fn (User $user): object => new LoginCodeRequested($user, LoginCodePurpose::Login), AuditEvent::LoginCodeRequested, ['purpose' => 'login']],
    'a revoked device' => [fn (User $user): object => new DeviceTrustRevoked($user, 'device-id', 'password_reset'), AuditEvent::DeviceRevoked, ['device_id' => 'device-id', 'reason' => 'password_reset']],
    'a revoked access token' => [fn (User $user): object => new AccessTokenRevoked($user, '7', 'two_factor_reset'), AuditEvent::TokenRevoked, ['reason' => 'two_factor_reset']],
    'a revoked session' => [fn (User $user): object => new SessionRevoked($user, hash('sha256', 'session-id')), AuditEvent::SessionRevoked, []],
]);

it('records who revoked a session, by the hash of its id', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $sessionId = Str::random(40);
    DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    $this->withToken($user->createToken('phone')->plainTextToken);
    $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

    $this->deleteJson('/api/user/sessions/session:'.hash('sha256', $sessionId))->assertNoContent();

    $revoked = auditEntry(AuditEvent::SessionRevoked);
    expect($revoked->actor?->is($user))->toBeTrue()
        ->and($revoked->credential_hash)->toBe(hash('sha256', $sessionId))
        ->and($revoked->channel)->toBe('token');
});

it('records a reset with the admin who did it as the actor', function (): void {
    Notification::fake();
    $admin = User::factory()->create();
    $user = User::factory()->withTwoFactorAuthentication()->create();
    $user->createToken('phone');
    $this->actingAs($admin);

    app(ResetTwoFactorAuthentication::class)($user, $admin);

    $reset = auditEntry(AuditEvent::TwoFactorReset);
    $revoked = auditEntry(AuditEvent::TokenRevoked);
    expect($reset->subject?->is($user))->toBeTrue()
        ->and($reset->actor?->is($admin))->toBeTrue()
        ->and($revoked->actor?->is($admin))->toBeTrue()
        ->and($revoked->metadata)->toBe(['reason' => 'two_factor_reset']);
});

it('records a reset from the console without an actor', function (): void {
    Notification::fake();
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $this->artisan('authentication:two-factor:reset', ['user' => $user->email])->assertSuccessful();

    $reset = auditEntry(AuditEvent::TwoFactorReset);
    expect($reset->actor)->toBeNull()
        ->and($reset->subject?->is($user))->toBeTrue()
        ->and($reset->ip_address)->toBeNull()
        ->and($reset->request_id)->toBeNull();
});

it('records nothing when the audit log is disabled', function (): void {
    config(['authentication.audit.enabled' => false]);

    $this->loginForToken(User::factory()->create());

    expect(AuditLog::query()->count())->toBe(0);
});

it('hands the entries to the configured record action', function (): void {
    $this->app->singleton(RecordingAuditLogAction::class);
    config(['authentication.audit.actions.record_audit_log' => RecordingAuditLogAction::class]);
    $user = User::factory()->create();

    $this->loginForToken($user);

    $entries = app(RecordingAuditLogAction::class)->entries;
    expect(AuditLog::query()->count())->toBe(0)
        ->and(array_map(fn (AuditEntry $entry): AuditEvent => $entry->event, $entries))->toBe([AuditEvent::LoginSucceeded, AuditEvent::TokenCreated])
        ->and($entries[0]->subject?->is($user))->toBeTrue();
});
