<?php

declare(strict_types=1);

use Igne\Auth\LoginCodes\Actions\ConsumeLoginCodeAction;
use Igne\Auth\LoginCodes\ConsumeLoginCodeResult;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

function consumeLoginCode(User $user, string $code, LoginCodePurpose $purpose = LoginCodePurpose::Login): ConsumeLoginCodeResult
{
    return app(ConsumeLoginCodeAction::class)->execute($user, $purpose, $code);
}

it('consumes a correct code once', function (): void {
    $user = User::factory()->withoutPassword()->create();
    $loginCode = LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

    $first = consumeLoginCode($user, '123456');
    $second = consumeLoginCode($user, '123456');

    expect($first)->toBe(ConsumeLoginCodeResult::Ok)
        ->and($second)->toBe(ConsumeLoginCodeResult::NotFound)
        ->and($loginCode->fresh()->consumed_at)->not->toBeNull();
});

it('rejects a code after it expires', function (): void {
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    $this->travel(10)->minutes();

    $result = consumeLoginCode($user, '123456');

    expect($result)->toBe(ConsumeLoginCodeResult::Expired);
});

it('kills the code at the fifth wrong attempt', function (): void {
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    $wrongAttempts = collect(range(1, 5))->map(fn (): ConsumeLoginCodeResult => consumeLoginCode($user, '000000'));

    $result = consumeLoginCode($user, '123456');

    expect($wrongAttempts->unique()->all())->toBe([ConsumeLoginCodeResult::Incorrect])
        ->and($result)->toBe(ConsumeLoginCodeResult::TooManyAttempts);
});

it('keeps counting a user\'s wrong attempts when a new code is sent', function (): void {
    config(['authentication.login_codes.rate_limit_attempts.max_attempts_per_user' => 3]);
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    collect(range(1, 3))->each(fn (): ConsumeLoginCodeResult => consumeLoginCode($user, '000000'));
    LoginCode::factory()->for($user, 'authenticatable')->withCode('654321')->create();

    $result = consumeLoginCode($user, '654321');

    expect($result)->toBe(ConsumeLoginCodeResult::RateLimited);
});

it('forgets a user\'s wrong attempts after a correct code', function (): void {
    config(['authentication.login_codes.rate_limit_attempts.max_attempts_per_user' => 3]);
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    collect(range(1, 2))->each(fn (): ConsumeLoginCodeResult => consumeLoginCode($user, '000000'));
    consumeLoginCode($user, '123456');
    LoginCode::factory()->for($user, 'authenticatable')->withCode('654321')->create();
    collect(range(1, 2))->each(fn (): ConsumeLoginCodeResult => consumeLoginCode($user, '000000'));

    $result = consumeLoginCode($user, '654321');

    expect($result)->toBe(ConsumeLoginCodeResult::Ok);
});

it('accepts a code made under the previous app key', function (): void {
    $user = User::factory()->withoutPassword()->create();
    $previousKey = config('app.key');
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => [$previousKey]]);

    $result = consumeLoginCode($user, '123456');

    expect($result)->toBe(ConsumeLoginCodeResult::Ok);
});

it('does not accept a code made under a key that is no longer listed', function (): void {
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => []]);

    $result = consumeLoginCode($user, '123456');

    expect($result)->toBe(ConsumeLoginCodeResult::Incorrect);
});

it('lets only one of two concurrent requests consume a code', function (): void {
    $user = User::factory()->withoutPassword()->create();
    $loginCode = LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();
    $consumedAt = now()->subHour()->startOfSecond();
    LoginCode::retrieved(function (LoginCode $retrieved) use ($consumedAt): void {
        // The other request consumes the code right after this one read it.
        DB::table('login_codes')->where('id', $retrieved->id)->whereNull('consumed_at')->update(['consumed_at' => $consumedAt]);
    });

    $result = consumeLoginCode($user, '123456');

    expect($result)->toBe(ConsumeLoginCodeResult::NotFound)
        ->and($loginCode->fresh()->consumed_at->equalTo($consumedAt))->toBeTrue();
});

it('only consumes a code made for the purpose', function (): void {
    $user = User::factory()->withoutPassword()->create();
    $loginCode = LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

    $result = consumeLoginCode($user, '123456', LoginCodePurpose::Confirm);

    expect($result)->toBe(ConsumeLoginCodeResult::NotFound)
        ->and($loginCode->fresh()->consumed_at)->toBeNull();
});
