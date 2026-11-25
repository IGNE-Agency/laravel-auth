<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;

describe('absolute lifetime', function (): void {
    it('rejects a token older than expires_in_minutes with 401', function (): void {
        config(['authentication.tokens.expires_in_minutes' => 60]);
        $this->freezeTime();
        $token = $this->loginForToken(User::factory()->create());

        $this->travel(61)->minutes();
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertUnauthorized();
    });

    it('keeps a token without an expiry when expires_in_minutes is null', function (): void {
        config(['authentication.tokens.expires_in_minutes' => null]);
        $this->freezeTime();
        $user = User::factory()->create();

        $loginResponse = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->forgetAuthenticatedUsers();
        $this->travel(2)->years();
        $response = $this->withToken($loginResponse->json('token'))->getJson('/api/user');

        $loginResponse->assertOk()->assertJson(['expires_at' => null]);
        $response->assertOk();
    });
});

describe('idle lifetime', function (): void {
    beforeEach(function (): void {
        config(['authentication.tokens.expires_in_minutes' => null]);
        $this->freezeTime();
    });

    it('rejects a token unused for longer than idle_expires_in_minutes with 401', function (): void {
        config(['authentication.tokens.idle_expires_in_minutes' => 120]);
        $token = $this->loginForToken(User::factory()->create());

        $this->travel(121)->minutes();
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertUnauthorized();
    });

    it('keeps a token that is used within idle_expires_in_minutes', function (): void {
        config(['authentication.tokens.idle_expires_in_minutes' => 120]);
        $token = $this->loginForToken(User::factory()->create());
        $this->travel(100)->minutes();
        $this->withToken($token)->getJson('/api/user')->assertOk();
        $this->forgetAuthenticatedUsers();

        $this->travel(100)->minutes();
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk();
    });

    it('has no idle limit when idle_expires_in_minutes is null', function (): void {
        config(['authentication.tokens.idle_expires_in_minutes' => null]);
        $token = $this->loginForToken(User::factory()->create());

        $this->travel(1)->years();
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk();
    });
});
