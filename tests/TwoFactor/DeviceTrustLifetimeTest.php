<?php

declare(strict_types=1);

use Igne\Auth\AuthenticationServiceProvider;
use Igne\Auth\TwoFactor\DeviceTrustLifetime;
use Igne\Auth\TwoFactor\Exceptions\InvalidConfigurationException;

it('reads the lifetime of device trust from the config', function (): void {
    config(['authentication.two_factor.device_trust' => [
        'expires_in_days' => '7',
        'remember_expires_in_days' => 90,
        'extend_on_use' => true,
    ]]);

    $lifetime = DeviceTrustLifetime::fromConfig(config());

    expect($lifetime->daysFor(remember: false))->toBe(7)
        ->and($lifetime->daysFor(remember: true))->toBe(90)
        ->and($lifetime->extendOnUse)->toBeTrue();
});

it('trusts for the session by default', function (): void {
    $lifetime = DeviceTrustLifetime::fromConfig(config());

    expect($lifetime->daysFor(remember: false))->toBeNull()
        ->and($lifetime->daysFor(remember: true))->toBe(30);
});

it('rejects a lifetime that is not "session" or 1 to 365 days', function (string $key, mixed $value): void {
    config(["authentication.two_factor.device_trust.{$key}" => $value]);

    expect(fn (): DeviceTrustLifetime => DeviceTrustLifetime::fromConfig(config()))->toThrow(InvalidConfigurationException::class);
})->with([
    'null' => ['expires_in_days', null],
    '366 days' => ['expires_in_days', 366],
    '0 days' => ['expires_in_days', 0],
    'forever' => ['expires_in_days', 'forever'],
    'a session-long remembered device' => ['remember_expires_in_days', 'session'],
    'null remembered days' => ['remember_expires_in_days', null],
]);

it('validates the lifetime when the app boots', function (): void {
    config(['authentication.two_factor.device_trust.expires_in_days' => 'forever']);

    expect(function (): void {
        (new AuthenticationServiceProvider(app()))->boot();
    })
        ->toThrow(InvalidConfigurationException::class, 'The [authentication.two_factor.device_trust.expires_in_days] setting must be "session" or a number of days from 1 to 365, "forever" given.');
});
