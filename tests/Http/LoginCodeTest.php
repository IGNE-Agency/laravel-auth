<?php

declare(strict_types=1);

use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

describe('requesting a code', function (): void {
    it('answers 202 with the same body whether or not a code is sent', function (): void {
        $codeUser = User::factory()->withoutPassword()->create();
        $passwordUser = User::factory()->withoutLoginCodes()->create();
        Notification::fake();
        Event::fake([LoginCodeRequested::class]);

        $responses = collect([$codeUser->email, 'nobody@example.com', $passwordUser->email])
            ->map(fn (string $email): TestResponse => $this->postJson('/api/auth/login-code', ['email' => $email]));

        $responses->each(fn (TestResponse $response): TestResponse => $response->assertAccepted()->assertExactJson(['message' => 'If the account can log in with a code, we have emailed one.'])->assertMatchesOpenApi('authentication.login-code'));
        Notification::assertSentToTimes($codeUser, LoginCodeNotification::class, 1);
        Notification::assertNotSentTo($passwordUser, LoginCodeNotification::class);
        Event::assertDispatchedTimes(LoginCodeRequested::class, 1);
        Event::assertDispatched(LoginCodeRequested::class, fn (LoginCodeRequested $event): bool => $event->user->is($codeUser) && $event->purpose === LoginCodePurpose::Login);
    });

    it('answers 202 with the same body when the requests for an address are rate-limited', function (): void {
        $user = User::factory()->withoutPassword()->create();
        Notification::fake();

        foreach (range(1, 3) as $request) {
            $this->postJson('/api/auth/login-code', ['email' => $user->email])->assertAccepted()->assertMatchesOpenApi('authentication.login-code');
        }
        $response = $this->postJson('/api/auth/login-code', ['email' => $user->email]);

        $response->assertAccepted()->assertExactJson(['message' => 'If the account can log in with a code, we have emailed one.'])->assertMatchesOpenApi('authentication.login-code');
        Notification::assertSentToTimes($user, LoginCodeNotification::class, 3);
    });

    it('finds the user by the lowercased email address when Fortify lowercases usernames', function (): void {
        config(['fortify.lowercase_usernames' => true]);
        $user = User::factory()->withoutPassword()->create(['email' => 'ada@example.com']);
        Notification::fake();

        $this->postJson('/api/auth/login-code', ['email' => 'Ada@Example.com'])->assertAccepted()->assertMatchesOpenApi('authentication.login-code');

        Notification::assertSentToTimes($user, LoginCodeNotification::class, 1);
    });

    it('rejects an invalid email address with 422', function (): void {
        Notification::fake();

        $response = $this->postJson('/api/auth/login-code', ['email' => 'not-an-email']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email field must be a valid email address.'])->assertMatchesOpenApi('authentication.login-code');
        Notification::assertNothingSent();
    });
});

describe('logging in with a code', function (): void {
    it('gives a bearer client an access token for an emailed code', function (): void {
        $user = User::factory()->withoutPassword()->create();
        Notification::fake();
        $this->postJson('/api/auth/login-code', ['email' => $user->email])->assertAccepted()->assertMatchesOpenApi('authentication.login-code');

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $this->lastLoginCodeNotification($user)->code]);

        $response->assertOk()->assertJson(['two_factor' => false])->assertJsonStructure(['token', 'expires_at'])->assertMatchesOpenApi('authentication.login');
        expect($user->tokens()->count())->toBe(1)
            ->and($user->loginCodes()->sole()->consumed_at)->not->toBeNull();
    });

    it('logs the SPA into the session with a code', function (): void {
        $user = User::factory()->withoutPassword()->create();
        LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

        $response = $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => '123456']);

        $response->assertOk()->assertExactJson(['two_factor' => false])->assertMatchesOpenApi('authentication.login');
        $this->assertAuthenticatedAs($user, 'web');
    });

    it('rejects a used code with 422', function (): void {
        $user = User::factory()->withoutPassword()->create();
        LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
        $this->loginForToken($user, '123456');

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => '123456']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'These credentials do not match our records.'])->assertMatchesOpenApi('authentication.login');
        expect($user->tokens()->count())->toBe(1);
    });

    it('rejects a confirmation code with 422', function (): void {
        $user = User::factory()->withoutPassword()->create();
        LoginCode::factory()->for($user, 'authenticatable')->forConfirmation()->withCode('123456')->create();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => '123456']);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('email')->assertMatchesOpenApi('authentication.login');
        expect($user->loginCodes()->sole()->consumed_at)->toBeNull();
    });

    it('rejects the code of a user that can\'t log in with codes with 422', function (): void {
        $user = User::factory()->withoutLoginCodes()->create();
        LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => '123456']);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('email')->assertMatchesOpenApi('authentication.login');
        expect($user->tokens()->count())->toBe(0);
    });

    it('logs in a user who may use both with their password or a code', function (): void {
        $user = User::factory()->create();
        LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

        $this->loginForToken($user, 'password');
        $this->loginForToken($user, '123456');

        expect($user->tokens()->count())->toBe(2);
    });
});
