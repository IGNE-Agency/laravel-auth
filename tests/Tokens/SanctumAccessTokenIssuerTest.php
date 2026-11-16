<?php

declare(strict_types=1);

use Igne\Auth\Events\AccessTokenIssued;
use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\Tokens\AccessToken;
use Igne\Auth\Tokens\Contracts\IssuesAccessTokens;
use Illuminate\Foundation\Auth\User as UserWithoutTokens;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

it('issues a Sanctum token and announces it', function (): void {
    Event::fake([AccessTokenIssued::class]);
    $this->freezeTime();
    $user = User::factory()->create();

    $token = app(IssuesAccessTokens::class)->issue($user, 'Laptop');

    expect(PersonalAccessToken::findToken($token->plainTextToken)?->getKey())->toEqual($token->id)
        ->and($token->expiresAt?->toJSON())->toBe(now()->addDays(30)->toJSON());
    Event::assertDispatched(AccessTokenIssued::class, fn (AccessTokenIssued $event): bool => $event->user->is($user)
        && $event->tokenId === $token->id
        && $event->name === 'Laptop');
});

it('refuses a user model without HasApiTokens', function (): void {
    $user = new UserWithoutTokens;

    app(IssuesAccessTokens::class)->issue($user, 'Laptop');
})->throws(LogicException::class);

it('revokes all tokens of the user and announces each', function (): void {
    Event::fake([AccessTokenRevoked::class]);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $issuer = app(IssuesAccessTokens::class);
    $issuer->issue($user, 'Laptop');
    $issuer->issue($user, 'Phone');
    $issuer->issue($otherUser, 'Laptop');

    $issuer->revokeAll($user);

    expect($user->tokens()->count())->toBe(0)
        ->and($otherUser->tokens()->count())->toBe(1);
    Event::assertDispatchedTimes(AccessTokenRevoked::class, 2);
});

it('lists the tokens of the user newest first, without their secrets', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $issuer = app(IssuesAccessTokens::class);
    $laptop = $issuer->issue($user, 'Laptop');
    $this->travel(1)->minutes();
    $phone = $issuer->issue($user, 'Phone');

    $tokens = $issuer->list($user);

    expect($tokens->map(fn (AccessToken $token): array => [$token->id, $token->name])->all())
        ->toBe([[$phone->id, 'Phone'], [$laptop->id, 'Laptop']]);
});

it('knows the current token only when the user authenticated with one', function (): void {
    $issuer = app(IssuesAccessTokens::class);
    $tokenUser = User::factory()->create();
    $token = $issuer->issue($tokenUser, 'Laptop');
    $sessionUser = User::factory()->create();

    $tokenUser->withAccessToken(PersonalAccessToken::findToken($token->plainTextToken));
    $sessionUser->withAccessToken(new TransientToken);

    expect($issuer->currentTokenId($tokenUser))->toBe($token->id)
        ->and($issuer->currentTokenId($sessionUser))->toBeNull();
});

it('does not revoke anything for a session user', function (): void {
    Event::fake([AccessTokenRevoked::class]);
    $issuer = app(IssuesAccessTokens::class);
    $user = User::factory()->create();
    $issuer->issue($user, 'Laptop');

    $issuer->revokeCurrent($user->withAccessToken(new TransientToken));

    expect($user->tokens()->count())->toBe(1);
    Event::assertNotDispatched(AccessTokenRevoked::class);
});
