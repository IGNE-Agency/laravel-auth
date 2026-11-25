<?php

declare(strict_types=1);

use Igne\Auth\Events\DeviceTrustRevoked;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;

it('revokes the other devices when the user disables two-factor authentication', function (): void {
    Event::fake([DeviceTrustRevoked::class]);
    $user = User::factory()->withTwoFactorAuthentication()->create();
    $currentDevice = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('current-secret')->create();
    $otherDevice = TrustedDevice::factory()->for($user, 'authenticatable')->create();
    $this->withToken($user->createToken('test')->plainTextToken)->withHeader('X-Two-Factor-Device', $currentDevice->id.'|current-secret');
    $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

    $response = $this->deleteJson('/api/user/two-factor-authentication');

    $response->assertOk();
    expect(TrustedDevice::pluck('id')->all())->toBe([$currentDevice->id]);
    Event::assertDispatched(DeviceTrustRevoked::class, fn (DeviceTrustRevoked $event): bool => $event->deviceId === $otherDevice->id && $event->reason === 'two_factor_disabled');
});

it('revokes the other devices when the user changes their password', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();
    $currentDevice = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('current-secret')->create();
    TrustedDevice::factory()->for($user, 'authenticatable')->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)
        ->withHeader('X-Two-Factor-Device', $currentDevice->id.'|current-secret')
        ->putJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response->assertOk();
    expect(TrustedDevice::pluck('id')->all())->toBe([$currentDevice->id]);
});

it('revokes all devices when the user resets their password', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();
    TrustedDevice::factory()->for($user, 'authenticatable')->count(2)->create();
    $otherUsersDevice = TrustedDevice::factory()->for(User::factory()->create(), 'authenticatable')->create();

    $response = $this->postJson('/api/auth/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertOk();
    expect(TrustedDevice::pluck('id')->all())->toBe([$otherUsersDevice->id]);
});
