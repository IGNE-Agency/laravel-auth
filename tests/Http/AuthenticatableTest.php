<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;

it('returns the authenticated user without a wrapper', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $this->loginForToken($user);

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()->assertExactJson(['id' => $user->id, 'email' => 'ada@example.com']);
});

it('answers a guest with a JSON 401, even without an Accept header', function () {
    $response = $this->get('/api/user');

    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

it('answers a failed login with JSON, even without an Accept header', function () {
    $user = User::factory()->create();

    $response = $this->post('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);

    $response->assertUnprocessable()->assertJsonValidationErrorFor('email');
});
