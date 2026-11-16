<?php

declare(strict_types=1);

use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware([AcceptJson::class, 'api', StartHeaderSession::class, 'auth:sanctum', 'password.confirm'])
        ->get('confirmed-area', fn (): array => ['confirmed' => true]);
});

it('confirms the password of a bearer client through its header session', function () {
    $token = $this->loginForToken(User::factory()->create());
    $this->withToken($token);

    $before = $this->getJson('/confirmed-area');
    $confirmation = $this->postJson('/api/user/confirm-password', ['password' => 'password']);
    $after = $this->getJson('/confirmed-area');

    $before->assertStatus(423);
    $confirmation->assertCreated();
    $after->assertOk();
});

it('reports the confirmation status of a bearer client', function () {
    $token = $this->loginForToken(User::factory()->create());
    $this->withToken($token);

    $before = $this->getJson('/api/user/confirmed-password-status');
    $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();
    $after = $this->getJson('/api/user/confirmed-password-status');

    $before->assertExactJson(['confirmed' => false]);
    $after->assertExactJson(['confirmed' => true]);
});

it('rejects a wrong password with 422', function () {
    $token = $this->loginForToken(User::factory()->create());

    $response = $this->withToken($token)->postJson('/api/user/confirm-password', ['password' => 'wrong']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The provided password was incorrect.']);
    $this->getJson('/confirmed-area')->assertStatus(423);
});

it('does not share a confirmation between tokens', function () {
    $user = User::factory()->create();
    $confirmedToken = $this->loginForToken($user);
    $otherToken = $this->loginForToken($user);
    $this->withToken($confirmedToken)->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();
    $this->forgetAuthenticatedUsers();

    $response = $this->withToken($otherToken)->getJson('/confirmed-area');

    $response->assertStatus(423);
});
