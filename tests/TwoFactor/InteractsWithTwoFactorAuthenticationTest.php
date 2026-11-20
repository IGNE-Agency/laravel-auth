<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;
use Laravel\Fortify\Features;

it('makes a user who requires two-factor authentication set it up until they enable it', function (): void {
    config(['authentication.two_factor.required' => true]);

    expect(User::factory()->make()->mustSetUpTwoFactorAuthentication())->toBeTrue()
        ->and(User::factory()->withTwoFactorAuthentication()->make()->mustSetUpTwoFactorAuthentication())->toBeFalse();
});

it('does not make a user set up two-factor authentication when it is not required', function (): void {
    config(['authentication.two_factor.required' => false]);

    expect(User::factory()->make()->mustSetUpTwoFactorAuthentication())->toBeFalse();
});

it('does not make a user set up two-factor authentication when the feature is off', function (): void {
    config([
        'authentication.two_factor.required' => true,
        'fortify.features' => [Features::updatePasswords()],
    ]);

    expect(User::factory()->make()->mustSetUpTwoFactorAuthentication())->toBeFalse();
});
