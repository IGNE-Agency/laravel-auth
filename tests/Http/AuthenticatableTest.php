<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;

it('returns the authenticated user without a wrapper', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = $this->loginForToken($user);

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()->assertExactJson([
        'id' => $user->id,
        'email' => 'ada@example.com',
        'confirmation_method' => 'password',
        'two_factor_enabled' => false,
        'two_factor_required' => false,
    ])->assertMatchesOpenApi('authentication.user');
});

it('tells whether the user set up two-factor authentication and must have it', function () {
    config(['authentication.two_factor.required' => true]);
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/user');

    $response->assertOk()->assertJson(['two_factor_enabled' => true, 'two_factor_required' => true]);
});

it('tells how the user confirms their identity', function (Closure $makeUser, ?string $confirmationMethod): void {
    $user = $makeUser();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api/user');

    $response->assertOk()->assertJsonPath('confirmation_method', $confirmationMethod)->assertMatchesOpenApi('authentication.user');
})->with([
    'a user with a password, who may also use codes' => [fn (): User => User::factory()->create(), 'password'],
    'a user without a password, with two-factor authentication' => [fn () => User::factory()->withoutPassword()->withTwoFactorAuthentication()->create(), 'totp'],
    'a user without a password' => [fn (): User => User::factory()->withoutPassword()->create(), 'login_code'],
    'a user with neither' => [fn (): User => User::factory()->withoutPassword()->withoutLoginCodes()->create(), null],
]);

it('answers a guest with a JSON 401, even without an Accept header', function (): void {
    $response = $this->get('/api/user');

    $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.'])->assertMatchesOpenApi('authentication.user');
});

it('answers a failed login with JSON, even without an Accept header', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);

    $response->assertUnprocessable()->assertJsonValidationErrorFor('email')->assertMatchesOpenApi('authentication.login');
});
