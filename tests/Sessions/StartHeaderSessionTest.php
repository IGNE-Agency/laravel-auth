<?php

declare(strict_types=1);

use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Sessions\Http\Middleware\StartHeaderSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AcceptJson::class, 'api', StartHeaderSession::class])->prefix('header-session')->group(function (): void {
        Route::get('/', fn (Request $request): array => [
            'id' => $request->session()->getId(),
            'value' => $request->session()->get('value'),
            'is_default_store' => $request->session() === app('session.store'),
        ]);
        Route::post('/', function (Request $request): array {
            $request->session()->put('value', $request->input('value'));

            return ['id' => $request->session()->getId()];
        });
        Route::post('forget', function (Request $request): array {
            $request->session()->forget('value');

            return ['id' => $request->session()->getId()];
        });
        Route::post('regenerate', function (Request $request): array {
            $request->session()->regenerate();

            return ['id' => $request->session()->getId()];
        });
    });
});

it('starts a fresh session when no header is sent', function (): void {
    $first = $this->getJson('/header-session');
    $second = $this->getJson('/header-session');

    expect($first->json('id'))->toMatch('/^[a-zA-Z0-9]{40}$/')
        ->and($second->json('id'))->not->toBe($first->json('id'));
});

it('resumes the session named by the challenge header', function (): void {
    $sessionId = $this->postJson('/header-session', ['value' => 'kept'])->json('id');

    $response = $this->withHeader('X-Auth-Challenge', $sessionId)->getJson('/header-session');

    $response->assertOk()->assertJson(['id' => $sessionId, 'value' => 'kept']);
});

it('derives a stable session from the bearer token', function (): void {
    $sessionId = $this->withToken('token-a')->postJson('/header-session', ['value' => 'kept'])->json('id');

    $sameToken = $this->withToken('token-a')->getJson('/header-session');
    $otherToken = $this->withToken('token-b')->getJson('/header-session');

    $sameToken->assertJson(['id' => $sessionId, 'value' => 'kept']);
    $otherToken->assertJson(['value' => null]);
    expect($otherToken->json('id'))->not->toBe($sessionId)
        ->and($sessionId)->not->toContain('token-a');
});

it('keeps the header session apart from the default session store', function (): void {
    $response = $this->postJson('/header-session', ['value' => 'kept']);

    expect(app('session.store')->get('value'))->toBeNull();
    $this->withHeader('X-Auth-Challenge', $response->json('id'))
        ->getJson('/header-session')
        ->assertJson(['is_default_store' => false]);
});

it('never sets a cookie', function (): void {
    $response = $this->postJson('/header-session', ['value' => 'kept']);

    expect($response->headers->getCookies())->toBe([]);
});

it('destroys the incoming session when the request moves to a new ID', function (): void {
    $incomingId = $this->postJson('/header-session', ['value' => 'kept'])->json('id');

    $newId = $this->withHeader('X-Auth-Challenge', $incomingId)->postJson('/header-session/regenerate')->json('id');

    expect($newId)->not->toBe($incomingId);
    $this->withHeader('X-Auth-Challenge', $incomingId)->getJson('/header-session')->assertJson(['value' => null]);
    $this->withHeader('X-Auth-Challenge', $newId)->getJson('/header-session')->assertJson(['value' => 'kept']);
});

it('forgets a session whose data was removed', function (): void {
    $sessionId = $this->postJson('/header-session', ['value' => 'kept'])->json('id');
    $this->withHeader('X-Auth-Challenge', $sessionId)->postJson('/header-session/forget');

    $response = $this->withHeader('X-Auth-Challenge', $sessionId)->getJson('/header-session');

    $response->assertJson(['value' => null]);
});

it('expires a challenge session after challenge_lifetime_minutes', function (): void {
    $this->freezeTime();
    $sessionId = $this->postJson('/header-session', ['value' => 'kept'])->json('id');

    $this->travel(11)->minutes();
    $response = $this->withHeader('X-Auth-Challenge', $sessionId)->getJson('/header-session');

    $response->assertJson(['value' => null]);
});

it('leaves a session-mode request on its cookie session', function (): void {
    $response = $this->fromSpa()->getJson('/header-session');

    $response->assertJson(['is_default_store' => true]);
});
