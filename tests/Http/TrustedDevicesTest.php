<?php

declare(strict_types=1);

use Igne\Auth\Events\DeviceTrustRevoked;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('lists the user\'s own trusted devices and marks the one the request comes from', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $token = $user->createToken('phone');
    $tokenDevice = TrustedDevice::factory()->for($user, 'authenticatable')->forAccessToken((string) $token->accessToken->getKey())->create([
        'name' => 'Phone',
        'ip_address' => '10.0.0.1',
        'verified_at' => Date::now()->subDay(),
    ]);
    $laptop = TrustedDevice::factory()->for($user, 'authenticatable')->create([
        'name' => 'Laptop',
        'ip_address' => '10.0.0.2',
        'last_used_at' => Date::now()->subHour(),
    ]);
    TrustedDevice::factory()->for($user, 'authenticatable')->expired()->create();
    TrustedDevice::factory()->for(User::factory(), 'authenticatable')->create();

    $response = $this->withToken($token->plainTextToken)->getJson('/api/user/devices');

    $response->assertOk()->assertExactJson(['data' => [
        [
            'id' => $laptop->id,
            'name' => 'Laptop',
            'ip_address' => '10.0.0.2',
            'remembered' => true,
            'last_used_at' => $laptop->last_used_at?->toJSON(),
            'expires_at' => $laptop->expires_at?->toJSON(),
            'is_current' => false,
        ],
        [
            'id' => $tokenDevice->id,
            'name' => 'Phone',
            'ip_address' => '10.0.0.1',
            'remembered' => false,
            'last_used_at' => $tokenDevice->fresh()?->last_used_at?->toJSON(),
            'expires_at' => null,
            'is_current' => true,
        ],
    ]]);
});

it('marks the remembered device whose device token the request sends', function () {
    $user = User::factory()->create();
    $device = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create();

    $response = $this->withToken($user->createToken('phone')->plainTextToken)
        ->withHeader('X-Two-Factor-Device', $device->id.'|device-secret')
        ->getJson('/api/user/devices');

    $response->assertOk()->assertJsonPath('data.0.id', $device->id)->assertJsonPath('data.0.is_current', true);
});

it('returns 401 to a guest', function () {
    $this->getJson('/api/user/devices')->assertUnauthorized();
});

describe('revoking a device', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->withToken($this->user->createToken('phone')->plainTextToken);
    });

    it('revokes the user\'s device after a password confirmation', function () {
        Event::fake([DeviceTrustRevoked::class]);
        $device = TrustedDevice::factory()->for($this->user, 'authenticatable')->create();
        $otherDevice = TrustedDevice::factory()->for($this->user, 'authenticatable')->create();
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson("/api/user/devices/{$device->id}");

        $response->assertNoContent();
        $this->assertModelMissing($device);
        $this->assertModelExists($otherDevice);
        Event::assertDispatched(DeviceTrustRevoked::class, fn (DeviceTrustRevoked $event): bool => $event->deviceId === $device->id && $event->reason === 'revoked');
    });

    it('returns 423 without a recent password confirmation', function () {
        $device = TrustedDevice::factory()->for($this->user, 'authenticatable')->create();

        $response = $this->deleteJson("/api/user/devices/{$device->id}");

        $response->assertStatus(423);
        $this->assertModelExists($device);
    });

    it('returns 404 for a device of another user', function () {
        $device = TrustedDevice::factory()->for(User::factory(), 'authenticatable')->create();
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson("/api/user/devices/{$device->id}");

        $response->assertNotFound()->assertJsonPath('message', 'Not Found');
        $this->assertModelExists($device);
    });
});
