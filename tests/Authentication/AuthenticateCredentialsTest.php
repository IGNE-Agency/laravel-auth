<?php

declare(strict_types=1);

use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('checks the credentials once when Fortify calls it twice for one login', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/api/auth/login', 'POST', ['email' => $user->email, 'password' => 'password']);
    $authenticate = app(AuthenticateCredentials::class);
    DB::enableQueryLog();

    $first = $authenticate($request);
    $second = $authenticate($request);

    expect($first)->toBe($second)
        ->and($first->is($user))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(1);
});

it('records the password as the proved factor', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/api/auth/login', 'POST', ['email' => $user->email, 'password' => 'password']);

    app(AuthenticateCredentials::class)($request);

    expect($request->attributes->get('authentication.methods'))->toBe([AuthenticationMethod::Password]);
});

it('remembers a failed check too', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/api/auth/login', 'POST', ['email' => $user->email, 'password' => 'wrong']);
    $authenticate = app(AuthenticateCredentials::class);

    $first = $authenticate($request);
    $user->forceFill(['password' => Hash::make('wrong')])->save();
    $second = $authenticate($request);

    expect($first)->toBeNull()
        ->and($second)->toBeNull()
        ->and($request->attributes->has('authentication.methods'))->toBeFalse();
});

it('rehashes a password hashed with outdated settings on login', function (): void {
    $user = User::factory()->create(['password' => Hash::make('password', ['rounds' => 4])]);
    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

    expect(password_get_info($user->fresh()->password)['options']['cost'])->toBe(5);
});
