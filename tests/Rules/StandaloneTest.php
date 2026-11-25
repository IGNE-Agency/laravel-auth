<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;

// The package by itself: nothing gives its errors another shape, so they are Laravel's. Whether a package that
// shapes errors or documents an API works with this one is for that package to test, not for this one.

it('answers a refusal as Laravel answers any HTTP exception', function (): void {
    config(['authentication.two_factor.required' => true]);

    $this->withToken(User::factory()->create()->createToken('test')->plainTextToken)
        ->getJson(route('authentication.sessions.index'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Set up two-factor authentication to continue.']);
});
