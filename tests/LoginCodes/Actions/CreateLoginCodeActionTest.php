<?php

declare(strict_types=1);

use Igne\Auth\LoginCodes\Actions\ConsumeLoginCodeAction;
use Igne\Auth\LoginCodes\Actions\CreateLoginCodeAction;
use Igne\Auth\LoginCodes\ConsumeLoginCodeResult;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

it('emails a six-digit code that expires in ten minutes and stores only its hash', function () {
    $this->freezeTime();
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();
    $request = Request::create('/api/auth/login-code', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'TestBrowser/1.0']);

    $loginCode = app(CreateLoginCodeAction::class)->execute($user, LoginCodePurpose::Login, $request);

    $notification = $this->lastLoginCodeNotification($user);
    expect($notification->code)->toMatch('/^\d{6}$/')
        ->and($notification->expiresAt->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($loginCode->fresh()->getAttributes())->toMatchArray([
            'purpose' => 'login',
            'attempts' => 0,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'TestBrowser/1.0',
        ])
        ->and($loginCode->code_hash)->toHaveLength(64)->not->toContain($notification->code)
        ->and($loginCode->matches($notification->code))->toBeTrue();
});

it('makes the previous code for the same purpose stop working when a new one is sent', function () {
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();
    $create = app(CreateLoginCodeAction::class);
    $create->execute($user, LoginCodePurpose::Confirm);
    $create->execute($user, LoginCodePurpose::Login);
    $previousCode = $this->lastLoginCodeNotification($user)->code;

    $create->execute($user, LoginCodePurpose::Login);

    expect($user->loginCodes()->where('purpose', LoginCodePurpose::Login)->count())->toBe(1)
        ->and($user->loginCodes()->where('purpose', LoginCodePurpose::Confirm)->count())->toBe(1)
        ->and(app(ConsumeLoginCodeAction::class)->execute($user, LoginCodePurpose::Login, $previousCode))->toBe(ConsumeLoginCodeResult::Incorrect);
});

it('links to the configured URL with the email address and code filled in', function () {
    config(['authentication.login_codes.link_url' => 'https://app.example.com/login?email={email}&code={code}']);
    $user = User::factory()->withoutPassword()->create(['email' => 'ada+test@example.com']);
    Notification::fake();

    app(CreateLoginCodeAction::class)->execute($user, LoginCodePurpose::Login);

    $notification = $this->lastLoginCodeNotification($user);
    expect($notification->url)->toBe('https://app.example.com/login?email=ada%2Btest%40example.com&code='.$notification->code);
});

it('sends no link when no link URL is configured', function () {
    config(['authentication.login_codes.link_url' => null]);
    $user = User::factory()->withoutPassword()->create();
    Notification::fake();

    app(CreateLoginCodeAction::class)->execute($user, LoginCodePurpose::Login);

    expect($this->lastLoginCodeNotification($user)->url)->toBeNull();
});
