<?php

declare(strict_types=1);

use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

describe('session mode', function (): void {
    it('logs the SPA into the session without issuing a token', function (): void {
        $user = User::factory()->create();

        $response = $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()->assertExactJson(['two_factor' => false]);
        $this->assertAuthenticatedAs($user, 'web');
        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('rejects a wrong password with 422', function (): void {
        $user = User::factory()->create();

        $response = $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest('web');
    });

    it('locks the login out with 429 after five failed attempts', function (): void {
        Event::fake([Lockout::class]);
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $response = $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertTooManyRequests();
        Event::assertDispatchedTimes(Lockout::class, 1);
        $this->assertGuest('web');
    });

    it('invalidates the session on logout', function (): void {
        $user = User::factory()->create();
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $response = $this->fromSpa()->postJson('/api/auth/logout');

        $response->assertNoContent();
        $this->forgetAuthenticatedUsers();
        $this->fromSpa()->getJson('/api/user')->assertUnauthorized();
    });
});

describe('token mode', function (): void {
    it('returns an access token with its expiry instead of a session', function (): void {
        $this->freezeTime();
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()
            ->assertJson(['two_factor' => false, 'expires_at' => now()->addDays(30)->toJSON()])
            ->assertJsonStructure(['two_factor', 'token', 'expires_at']);
        expect($response->headers->getCookies())->toBe([])
            ->and($user->tokens()->count())->toBe(1);
    });

    it('ignores remember', function (): void {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertOk();

        expect($user->fresh()->getAttribute('remember_token'))->toBeNull();
    });

    it('names the token after the device name, or else the user agent', function (): void {
        $user = User::factory()->create();

        $this->loginForToken($user, input: ['device_name' => 'Ada’s phone']);
        $this->withHeader('User-Agent', 'TestBrowser/1.0')->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        expect($user->tokens()->orderBy('id')->pluck('name')->all())->toBe(['Ada’s phone', 'TestBrowser/1.0']);
    });

    it('authenticates a later request with the access token, never with a session', function (): void {
        $user = User::factory()->create();
        $token = $this->loginForToken($user);

        $this->getJson('/api/user')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk()->assertJson(['id' => $user->id]);
        expect(auth('sanctum')->user()->currentAccessToken())->toBeInstanceOf(PersonalAccessToken::class);
    });

    it('revokes only the current token on logout', function (): void {
        Event::fake([AccessTokenRevoked::class]);
        $user = User::factory()->create();
        $currentToken = $this->loginForToken($user);
        $otherToken = $this->loginForToken($user);

        $response = $this->withToken($currentToken)->postJson('/api/auth/logout');

        $response->assertNoContent();
        $this->forgetAuthenticatedUsers();
        $this->withToken($currentToken)->getJson('/api/user')->assertUnauthorized();
        $this->forgetAuthenticatedUsers();
        $this->withToken($otherToken)->getJson('/api/user')->assertOk();
        Event::assertDispatched(AccessTokenRevoked::class, fn (AccessTokenRevoked $event): bool => $event->user->is($user) && $event->reason === 'logout');
    });
});

it('does not log in a user without a password, whatever password is sent', function (string $password, string $invalidField): void {
    $user = User::factory()->withoutPassword()->create();

    $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);

    $response->assertUnprocessable()->assertJsonValidationErrorFor($invalidField);
    expect($user->tokens()->count())->toBe(0);
})->with([
    'any password' => ['password', 'email'],
    'an empty password' => ['', 'password'],
]);
