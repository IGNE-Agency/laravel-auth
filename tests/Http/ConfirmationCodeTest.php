<?php

declare(strict_types=1);

use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\LoginCodes\Notifications\LoginCodeNotification;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AcceptJson::class, 'api', StartHeaderSession::class, 'auth:sanctum', 'password.confirm'])
        ->get('confirmed-area', fn (): array => ['confirmed' => true]);
});

it('emails a confirmation code without a link to a user who confirms with codes', function (): void {
    config(['authentication.login_codes.link_url' => 'https://app.example.com/login?code={code}']);
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();
    Event::fake([LoginCodeRequested::class]);

    $response = $this->withToken($user->createToken('test')->plainTextToken)->postJson('/api/user/confirm-password/code');

    $response->assertAccepted()->assertExactJson(['message' => 'We have emailed you a confirmation code.']);
    Notification::assertSentTo($user, LoginCodeNotification::class, fn (LoginCodeNotification $notification): bool => $notification->purpose === LoginCodePurpose::Confirm && $notification->url === null);
    Event::assertDispatched(LoginCodeRequested::class, fn (LoginCodeRequested $event): bool => $event->user->is($user) && $event->purpose === LoginCodePurpose::Confirm);
});

it('confirms the identity of a user without a password with an emailed code', function (): void {
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();
    $this->withToken($user->createToken('test')->plainTextToken);

    $before = $this->getJson('/confirmed-area');
    $this->postJson('/api/user/confirm-password/code')->assertAccepted();
    $confirmation = $this->postJson('/api/user/confirm-password', ['password' => $this->lastLoginCodeNotification($user)->code]);
    $after = $this->getJson('/confirmed-area');

    $before->assertStatus(423);
    $confirmation->assertCreated();
    $after->assertOk();
});

it('does not confirm with a login code', function (): void {
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    $this->withToken($user->createToken('test')->plainTextToken);

    $response = $this->postJson('/api/user/confirm-password', ['password' => '123456']);

    $response->assertUnprocessable()->assertJsonValidationErrors(['password' => 'The provided password was incorrect.']);
    $this->getJson('/confirmed-area')->assertStatus(423);
});

it('refuses a confirmation code to a user who confirms with a password with 403', function (): void {
    $user = User::factory()->create();
    Notification::fake();

    $response = $this->withToken($this->loginForToken($user))->postJson('/api/user/confirm-password/code');

    $response->assertForbidden()->assertExactJson(['message' => 'Your account does not confirm with an emailed code.']);
    Notification::assertNothingSent();
});

it('throttles confirmation code requests with 429', function (): void {
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();
    $this->withToken($user->createToken('test')->plainTextToken);

    foreach (range(1, 3) as $request) {
        $this->postJson('/api/user/confirm-password/code')->assertAccepted();
    }
    $response = $this->postJson('/api/user/confirm-password/code');

    $response->assertTooManyRequests();
    Notification::assertSentToTimes($user, LoginCodeNotification::class, 3);
});

it('answers a guest with 401', function (): void {
    $response = $this->postJson('/api/user/confirm-password/code');

    $response->assertUnauthorized();
});
