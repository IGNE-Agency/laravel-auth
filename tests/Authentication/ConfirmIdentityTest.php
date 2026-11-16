<?php

declare(strict_types=1);

use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Http\Middleware\EnsureTwoFactorAuthenticated;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware([AcceptJson::class, 'api', StartHeaderSession::class, 'auth:sanctum', 'password.confirm'])
        ->get('confirmed-area', fn (): array => ['confirmed' => true])
        ->withoutMiddleware(EnsureTwoFactorAuthenticated::class);
});

it('confirms a user with a password with their password, not with an authenticator code', function () {
    $user = User::factory()->withTwoFactorAuthentication()->create();
    $this->withToken($user->createToken('test')->plainTextToken);

    $withCode = $this->postJson('/api/user/confirm-password', ['password' => $this->twoFactorCode($user)]);
    $withPassword = $this->postJson('/api/user/confirm-password', ['password' => 'password']);

    $withCode->assertUnprocessable();
    $withPassword->assertCreated();
});

it('confirms a user without a password with a code from their authenticator app', function () {
    $user = User::factory()->withoutPassword()->withTwoFactorAuthentication()->create();
    $this->withToken($user->createToken('test')->plainTextToken);

    $before = $this->getJson('/confirmed-area');
    $confirmation = $this->postJson('/api/user/confirm-password', ['password' => $this->twoFactorCode($user)]);
    $after = $this->getJson('/confirmed-area');

    $before->assertStatus(423);
    $confirmation->assertCreated();
    $after->assertOk();
});

it('rejects a wrong or reused authenticator code', function () {
    $user = User::factory()->withoutPassword()->withTwoFactorAuthentication()->create();
    $this->withToken($user->createToken('test')->plainTextToken);
    $code = $this->twoFactorCode($user);
    $this->postJson('/api/user/confirm-password', ['password' => $code])->assertCreated();

    $reused = $this->postJson('/api/user/confirm-password', ['password' => $code]);
    $wrong = $this->postJson('/api/user/confirm-password', ['password' => '000000']);

    $reused->assertUnprocessable();
    $wrong->assertUnprocessable();
});

it('sends no emailed code to a user who confirms with an authenticator code', function () {
    $user = User::factory()->withoutPassword()->withTwoFactorAuthentication()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->postJson('/api/user/confirm-password/code');

    $response->assertForbidden()->assertJsonPath('code', 'login_code_unavailable');
});
