<?php

declare(strict_types=1);

use Igne\Auth\Events\SessionRevoked;
use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Store a web session of the user in the session table, and get its id.
 */
function storeWebSession(User $user, string $userAgent = 'Firefox', ?int $lastActivity = null): string
{
    $sessionId = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => $userAgent,
        'payload' => '',
        'last_activity' => $lastActivity ?? Date::now()->getTimestamp(),
    ]);

    return $sessionId;
}

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    $this->freezeSecond();
    $this->user = User::factory()->create(['remember_token' => 'old-remember-token']);
});

it('lists the user\'s web sessions and access tokens without raw ids, the current one first', function (): void {
    $laptopSessionId = storeWebSession($this->user, 'Firefox', Date::now()->subMinutes(5)->getTimestamp());
    storeWebSession($this->user, 'Old browser', Date::now()->subMinutes(config('session.lifetime') + 1)->getTimestamp());
    storeWebSession(User::factory()->create());
    $this->travel(-1)->hours();
    $tablet = $this->user->createToken('Tablet');
    $this->travel(1)->hours();
    $phone = $this->user->createToken('Phone');

    $response = $this->withToken($phone->plainTextToken)->getJson('/api/user/sessions');

    $response->assertOk()->assertExactJson(['data' => [
        [
            'id' => 'token:'.$phone->accessToken->getKey(),
            'type' => 'token',
            'name' => 'Phone',
            'ip_address' => null,
            'last_active_at' => Date::now()->toJSON(),
            'is_current' => true,
        ],
        [
            'id' => 'session:'.hash('sha256', $laptopSessionId),
            'type' => 'session',
            'name' => 'Firefox',
            'ip_address' => '10.0.0.1',
            'last_active_at' => Date::now()->subMinutes(5)->toJSON(),
            'is_current' => false,
        ],
        [
            'id' => 'token:'.$tablet->accessToken->getKey(),
            'type' => 'token',
            'name' => 'Tablet',
            'ip_address' => null,
            'last_active_at' => Date::now()->subHour()->toJSON(),
            'is_current' => false,
        ],
    ]]);
    expect($response->getContent())->not->toContain($laptopSessionId);
});

it('lists only the access tokens with another session driver', function (): void {
    config(['session.driver' => 'array']);
    storeWebSession($this->user);
    $token = $this->user->createToken('Phone');

    $response = $this->withToken($token->plainTextToken)->getJson('/api/user/sessions');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'token');
});

it('leaves out access tokens that expired or were idle too long', function (): void {
    config(['authentication.tokens.idle_expires_in_minutes' => 60]);
    $this->user->createToken('Expired', expiresAt: Date::now()->subMinute());
    $idle = $this->user->createToken('Idle');
    $idle->accessToken->forceFill(['last_used_at' => Date::now()->subHours(2)])->save();
    $token = $this->user->createToken('Phone');

    $response = $this->withToken($token->plainTextToken)->getJson('/api/user/sessions');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Phone');
});

it('returns 401 to a guest', function (): void {
    $this->getJson('/api/user/sessions')->assertUnauthorized();
});

describe('revoking from a token client', function (): void {
    beforeEach(function (): void {
        $this->currentToken = $this->user->createToken('Phone');
        $this->withToken($this->currentToken->plainTextToken);
    });

    it('ends a web session and stops "remember me" logins', function (): void {
        Event::fake([SessionRevoked::class]);
        $sessionId = storeWebSession($this->user);
        $otherSessionId = storeWebSession($this->user);
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson('/api/user/sessions/session:'.hash('sha256', $sessionId));

        $response->assertNoContent();
        expect(DB::table('sessions')->pluck('id')->all())->toBe([$otherSessionId])
            ->and($this->user->fresh()->remember_token)->not->toBe('old-remember-token');
        Event::assertDispatched(SessionRevoked::class, fn (SessionRevoked $event): bool => $event->sessionHash === hash('sha256', $sessionId));
    });

    it('revokes another access token', function (): void {
        $otherToken = $this->user->createToken('Tablet');
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson('/api/user/sessions/token:'.$otherToken->accessToken->getKey());

        $response->assertNoContent();
        $this->assertModelMissing($otherToken->accessToken);
        $this->assertModelExists($this->currentToken->accessToken);
    });

    it('returns 403 for the access token the request comes from', function (): void {
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson('/api/user/sessions/token:'.$this->currentToken->accessToken->getKey());

        $response->assertForbidden()->assertExactJson(['message' => 'Log out to end the session you are using.']);
        $this->assertModelExists($this->currentToken->accessToken);
    });

    it('returns 404 for a session or access token of another user', function (Closure $idOf): void {
        $otherUser = User::factory()->create();
        $sessionId = storeWebSession($otherUser);
        $token = $otherUser->createToken('Phone');
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson('/api/user/sessions/'.$idOf($sessionId, $token->accessToken));

        $response->assertNotFound()->assertJsonPath('message', 'Not Found');
        expect(DB::table('sessions')->count())->toBe(1)
            ->and(PersonalAccessToken::query()->count())->toBe(2);
    })->with([
        'a session' => [fn (string $sessionId): string => 'session:'.hash('sha256', $sessionId)],
        'an access token' => [fn (string $sessionId, PersonalAccessToken $token): string => 'token:'.$token->getKey()],
        'an unknown kind' => [fn (string $sessionId): string => 'device:'.$sessionId],
        'a malformed access token id' => [fn (string $sessionId): string => 'token:not-an-id'],
    ]);

    it('returns 423 without a recent password confirmation', function (): void {
        $sessionId = storeWebSession($this->user);

        $response = $this->deleteJson('/api/user/sessions/session:'.hash('sha256', $sessionId));

        $response->assertStatus(423);
        expect(DB::table('sessions')->count())->toBe(1);
    });

    it('revokes all other sessions and access tokens, keeping the current access token', function (): void {
        storeWebSession($this->user);
        storeWebSession($this->user);
        $otherUsersSessionId = storeWebSession($otherUser = User::factory()->create());
        $otherToken = $this->user->createToken('Tablet');
        $otherUsersToken = $otherUser->createToken('Phone');
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

        $response = $this->deleteJson('/api/user/sessions');

        $response->assertNoContent();
        expect(DB::table('sessions')->pluck('id')->all())->toBe([$otherUsersSessionId])
            ->and($this->user->fresh()->remember_token)->not->toBe('old-remember-token');
        $this->assertModelMissing($otherToken->accessToken);
        $this->assertModelExists($this->currentToken->accessToken);
        $this->assertModelExists($otherUsersToken->accessToken);
    });
});

describe('revoking from a cookie session', function (): void {
    beforeEach(function (): void {
        $this->currentSessionId = storeWebSession($this->user);
        $this->actingAs($this->user, 'web')
            ->fromSpa()
            ->withCredentials()
            ->withCookie(config('session.cookie'), $this->currentSessionId)
            ->withSession(['auth.password_confirmed_at' => Date::now()->getTimestamp()]);
    });

    it('marks the current cookie session', function (): void {
        storeWebSession($this->user);

        $response = $this->getJson('/api/user/sessions');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'session:'.hash('sha256', $this->currentSessionId))
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.is_current', false);
    });

    it('revokes all other sessions and access tokens, keeping the current session', function (): void {
        storeWebSession($this->user);
        $token = $this->user->createToken('Phone');

        $response = $this->deleteJson('/api/user/sessions');

        $response->assertNoContent();
        expect(DB::table('sessions')->where('user_id', $this->user->id)->pluck('id')->all())->toBe([$this->currentSessionId]);
        $this->assertModelMissing($token->accessToken);
    });

    it('returns 403 for the current session', function (): void {
        $response = $this->deleteJson('/api/user/sessions/session:'.hash('sha256', $this->currentSessionId));

        $response->assertForbidden()->assertExactJson(['message' => 'Log out to end the session you are using.']);
        expect(DB::table('sessions')->where('id', $this->currentSessionId)->exists())->toBeTrue();
    });
});
