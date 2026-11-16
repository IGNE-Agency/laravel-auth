<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Models\TrustedDevice;

it('stores only the hash of the device secret', function (): void {
    $device = TrustedDevice::factory()->for(User::factory()->create(), 'authenticatable')->withSecret('device-secret')->create();

    expect($device->token_hash)->toBe(hash('sha256', 'device-secret'))
        ->and($device->matchesSecret('device-secret'))->toBeTrue()
        ->and($device->matchesSecret('other-secret'))->toBeFalse()
        ->and($device->toArray())->not->toHaveKey('token_hash');
});

it('prunes expired devices and devices verified more than 365 days ago', function (): void {
    $user = User::factory()->create();
    $active = TrustedDevice::factory()->for($user, 'authenticatable')->create();
    $boundToToken = TrustedDevice::factory()->for($user, 'authenticatable')->forAccessToken('1')->create();
    TrustedDevice::factory()->for($user, 'authenticatable')->expired()->create();
    TrustedDevice::factory()->for($user, 'authenticatable')->forAccessToken('2')->create(['verified_at' => now()->subDays(366)]);

    $this->artisan('model:prune', ['--model' => [TrustedDevice::class]])->assertSuccessful();

    expect(TrustedDevice::pluck('id')->sort()->values()->all())->toBe(collect([$active->id, $boundToToken->id])->sort()->values()->all());
});
