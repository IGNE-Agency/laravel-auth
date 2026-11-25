<?php

declare(strict_types=1);

use Igne\Auth\Authentication\Notifications\ResetPasswordNotification;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

describe('forgot password', function (): void {
    it('answers the same success for a password user, an unknown email and a passwordless user', function (): void {
        Notification::fake();
        $passwordUser = User::factory()->create();
        $passwordlessUser = User::factory()->withoutPassword()->create();

        $responses = collect([$passwordUser->email, 'nobody@example.com', $passwordlessUser->email])
            ->map(fn (string $email): TestResponse => $this->postJson('/api/auth/forgot-password', ['email' => $email]));

        $responses->each(fn (TestResponse $response): TestResponse => $response->assertOk()->assertExactJson(['message' => 'We have emailed your password reset link.']));
        Notification::assertSentToTimes($passwordUser, ResetPasswordNotification::class, 1);
        Notification::assertNotSentTo($passwordlessUser, ResetPasswordNotification::class);
    });

    it('answers the same success when a password user asks again too soon', function (): void {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk()->assertExactJson(['message' => 'We have emailed your password reset link.']);
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    });

    it('rejects an invalid email address with 422', function (): void {
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email']);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('email');
        Notification::assertNothingSent();
    });

    it('throttles requests for the same email address with 429', function (): void {
        Notification::fake();

        foreach (range(1, 3) as $request) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        }
        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $response->assertTooManyRequests();
    });
});

describe('reset password', function (): void {
    it('sets the new password with a valid reset token', function (): void {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk()->assertExactJson(['message' => 'Your password has been reset.']);
        expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
    });

    it('rejects an invalid reset token with 422', function (): void {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'This password reset token is invalid.']);
        expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    });
});
