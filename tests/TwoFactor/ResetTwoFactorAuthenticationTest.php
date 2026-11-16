<?php

declare(strict_types=1);

use Igne\Auth\Events\TwoFactorAuthenticationReset;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Actions\ResetTwoFactorAuthentication;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Igne\Auth\TwoFactor\Notifications\TwoFactorAuthenticationResetNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function storeDatabaseSession(int $userId): void
{
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $userId,
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
}

it('disables two-factor authentication and logs the user out everywhere from the console', function () {
    config(['session.driver' => 'database']);
    Notification::fake();
    $user = User::factory()->withTwoFactorAuthentication()->create(['remember_token' => 'old-remember-token']);
    $otherUser = User::factory()->withTwoFactorAuthentication()->create();
    TrustedDevice::factory()->for($user, 'authenticatable')->count(2)->create();
    TrustedDevice::factory()->for($otherUser, 'authenticatable')->create();
    $user->createToken('phone');
    $otherUser->createToken('phone');
    storeDatabaseSession($user->id);
    storeDatabaseSession($otherUser->id);

    $this->artisan('authentication:two-factor:reset', ['user' => $user->email])->assertSuccessful();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->remember_token)->not->toBe('old-remember-token')
        ->and($user->trustedDevices()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($otherUser->trustedDevices()->count())->toBe(1)
        ->and($otherUser->tokens()->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $otherUser->id)->count())->toBe(1);
    Notification::assertSentTo($user, TwoFactorAuthenticationResetNotification::class, fn (TwoFactorAuthenticationResetNotification $notification): bool => $notification->resetBy === null);
});

it('finds the user for the command by id too, and fails for an unknown user', function () {
    Notification::fake();
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $this->artisan('authentication:two-factor:reset', ['user' => (string) $user->id])->assertSuccessful();
    $this->artisan('authentication:two-factor:reset', ['user' => 'nobody@example.com'])->assertFailed();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('announces who reset it, and sends the configured notification', function () {
    Event::fake([TwoFactorAuthenticationReset::class]);
    $admin = User::factory()->create();
    $user = User::factory()->withTwoFactorAuthentication()->create();

    app(ResetTwoFactorAuthentication::class)($user, $admin);

    Event::assertDispatched(TwoFactorAuthenticationReset::class, fn (TwoFactorAuthenticationReset $event): bool => $event->user->is($user) && $event->resetBy?->is($admin) === true);
});

it('sends no notification when none is configured', function () {
    config(['authentication.two_factor.reset_notification' => null]);
    Notification::fake();
    $user = User::factory()->withTwoFactorAuthentication()->create();

    app(ResetTwoFactorAuthentication::class)($user);

    Notification::assertNothingSent();
});

it('mails the reset with the default notification', function () {
    $resetAt = now();
    $admin = User::factory()->make(['name' => 'Ada']);

    $withAdmin = (new TwoFactorAuthenticationResetNotification($admin, $resetAt))->toMail(new User);
    $fromConsole = (new TwoFactorAuthenticationResetNotification(null, $resetAt))->toMail(new User);

    expect($withAdmin->subject)->toBe('Your two-factor authentication was reset')
        ->and($withAdmin->introLines[0])->toBe("Ada reset the two-factor authentication of your account on {$resetAt->toDayDateTimeString()}.")
        ->and($fromConsole->introLines[0])->toBe("The two-factor authentication of your account was reset on {$resetAt->toDayDateTimeString()}.");
});

it('denies resetting the two-factor authentication of others by default', function () {
    $admin = User::factory()->create();
    $user = User::factory()->withTwoFactorAuthentication()->create();

    expect(Gate::forUser($admin)->allows('resetTwoFactorAuthentication', [$user]))->toBeFalse();
});
