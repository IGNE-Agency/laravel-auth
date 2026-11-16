<?php

declare(strict_types=1);

use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Models\TrustedDevice;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AcceptJson::class, 'api', 'auth:sanctum'])->get('protected-area', fn (): array => ['protected' => true]);
});

describe('session mode', function (): void {
    it('trusts the session with a flag after the challenge, without a device cookie', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $login = $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $challenge = $this->fromSpa()->postJson('/api/auth/two-factor-challenge', ['code' => $this->twoFactorCode($user)]);
        $response = $this->fromSpa()->getJson('/protected-area');

        $login->assertOk()->assertExactJson(['two_factor' => true])->assertMatchesOpenApi('authentication.login');
        $challenge->assertNoContent()->assertCookieMissing('two_factor_device')->assertMatchesOpenApi('authentication.two-factor.challenge');
        $response->assertOk();
        expect(TrustedDevice::count())->toBe(0);
    });

    it('remembers the device for 30 days with remember_device', function (): void {
        $this->freezeSecond();
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertMatchesOpenApi('authentication.login');

        $response = $this->fromSpa()->postJson('/api/auth/two-factor-challenge', ['code' => $this->twoFactorCode($user), 'remember_device' => true]);

        $device = TrustedDevice::sole();
        $response->assertNoContent()->assertCookie('two_factor_device')->assertMatchesOpenApi('authentication.two-factor.challenge');
        expect($response->getCookie('two_factor_device')->getValue())->toStartWith($device->id.'|')
            ->and($response->getCookie('two_factor_device')->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
            ->and($device->remembered)->toBeTrue()
            ->and($device->expires_at->equalTo(now()->addDays(30)))->toBeTrue()
            ->and($device->authenticatable_id)->toBe($user->id);
    });

    it('trusts the device for "expires_in_days" without remember_device', function (): void {
        $this->freezeSecond();
        config(['authentication.two_factor.device_trust.expires_in_days' => 7]);
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertMatchesOpenApi('authentication.login');

        $response = $this->fromSpa()->postJson('/api/auth/two-factor-challenge', ['code' => $this->twoFactorCode($user)]);

        $device = TrustedDevice::sole();
        $response->assertNoContent()->assertCookie('two_factor_device')->assertMatchesOpenApi('authentication.two-factor.challenge');
        expect($device->remembered)->toBeFalse()
            ->and($device->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
    });

    it('skips the challenge on a device remembered by its cookie', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $device = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create();

        $response = $this->fromSpa()
            ->withCredentials()
            ->withCookie('two_factor_device', $device->id.'|device-secret')
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()->assertExactJson(['two_factor' => false])->assertMatchesOpenApi('authentication.login');
        $this->assertAuthenticatedAs($user, 'web');
    });
});

describe('token mode', function (): void {
    it('answers the login with a challenge, and the challenge with an access token', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $challenge = $this->loginForChallenge($user);
        $response = $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user)]);
        $protected = $this->withToken((string) $response->json('token'))->getJson('/protected-area');

        expect($challenge)->toHaveLength(40);
        $response->assertOk()
            ->assertJson(['device_token' => null])
            ->assertJsonStructure(['token', 'expires_at', 'device_token']);
        $protected->assertOk();
        expect($response->headers->getCookies())->toBe([])
            ->and(TrustedDevice::sole()->token_hash)->toBeNull();
    });

    it('gives a device token with remember_device, which skips the next challenge', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $deviceToken = (string) $this->passChallenge($this->loginForChallenge($user), [
            'code' => $this->twoFactorCode($user),
            'remember_device' => true,
        ])->assertOk()->json('device_token');

        $login = $this->withHeader('X-Two-Factor-Device', $deviceToken)
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->flushHeaders();
        $this->forgetAuthenticatedUsers();
        $protected = $this->withToken((string) $login->json('token'))->getJson('/protected-area');

        expect($deviceToken)->toStartWith(TrustedDevice::sole()->id.'|');
        $login->assertOk()->assertJson(['two_factor' => false])->assertMatchesOpenApi('authentication.login');
        $protected->assertOk();
    });

    it('accepts a challenge only once', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $challenge = $this->loginForChallenge($user);
        $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user)])->assertOk();

        $response = $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user, periodsAhead: 1)]);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('code');
    });

    it('expires a challenge after "challenge_lifetime_minutes"', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $challenge = $this->loginForChallenge($user);
        $this->travel(11)->minutes();

        $response = $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user)]);

        $response->assertUnprocessable()->assertJsonValidationErrorFor('code');
    });

    it('rejects a wrong code with 422 and keeps the challenge open', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $challenge = $this->loginForChallenge($user);

        $wrong = $this->passChallenge($challenge, ['code' => '000000']);
        $right = $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user)]);

        $wrong->assertUnprocessable()->assertJsonValidationErrors(['code' => 'The provided two factor authentication code was invalid.']);
        $right->assertOk();
    });

    it('throttles the challenge per challenged user', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $otherUser = User::factory()->withTwoFactorAuthentication()->create();
        $challenge = $this->loginForChallenge($user);
        $otherChallenge = $this->loginForChallenge($otherUser);

        foreach (range(1, 5) as $attempt) {
            $this->passChallenge($challenge, ['code' => '000000'])->assertUnprocessable();
        }
        $throttled = $this->passChallenge($challenge, ['code' => $this->twoFactorCode($user)]);
        $other = $this->passChallenge($otherChallenge, ['code' => $this->twoFactorCode($otherUser)]);

        $throttled->assertTooManyRequests();
        $other->assertOk();
    });
});

describe('trusted devices', function (): void {
    it('skips the challenge on a device remembered by its header, and trusts the new token', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $device = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create();

        $login = $this->withHeader('X-Two-Factor-Device', $device->id.'|device-secret')
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->flushHeaders();
        $this->forgetAuthenticatedUsers();
        $protected = $this->withToken((string) $login->json('token'))->getJson('/protected-area');

        $login->assertOk()->assertJson(['two_factor' => false])->assertMatchesOpenApi('authentication.login');
        $protected->assertOk();
    });

    it('challenges again on an expired device, or with a wrong secret', function (Closure $deviceToken): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $response = $this->withHeader('X-Two-Factor-Device', $deviceToken($user))
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertOk()->assertJson(['two_factor' => true])->assertMatchesOpenApi('authentication.login');
    })->with([
        'an expired device' => [fn (User $user): string => TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->expired()->create()->id.'|device-secret'],
        'a device verified more than 365 days ago' => [fn (User $user): string => TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create(['verified_at' => now()->subDays(366)])->id.'|device-secret'],
        'a wrong secret' => [fn (User $user): string => TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create()->id.'|wrong-secret'],
        'a device of another user' => [fn (): string => TrustedDevice::factory()->for(User::factory()->create(), 'authenticatable')->withSecret('device-secret')->create()->id.'|device-secret'],
    ]);

    it('extends the expiry on use with "extend_on_use", up to 365 days after the verification', function (int $verifiedDaysAgo, int $expectedDaysLeft): void {
        $this->freezeSecond();
        config(['authentication.two_factor.device_trust.extend_on_use' => true]);
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $device = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create([
            'verified_at' => now()->subDays($verifiedDaysAgo),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader('X-Two-Factor-Device', $device->id.'|device-secret')
            ->getJson('/protected-area')
            ->assertOk();

        expect($device->fresh()->expires_at->equalTo(now()->addDays($expectedDaysLeft)))->toBeTrue()
            ->and($device->fresh()->last_used_at->equalTo(now()))->toBeTrue();
    })->with([
        'by the remember lifetime' => [10, 30],
        'capped at 365 days after the verification' => [350, 15],
    ]);

    it('keeps the expiry without "extend_on_use"', function (): void {
        $this->freezeSecond();
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $device = TrustedDevice::factory()->for($user, 'authenticatable')->withSecret('device-secret')->create(['expires_at' => now()->addDay()]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader('X-Two-Factor-Device', $device->id.'|device-secret')
            ->getJson('/protected-area')
            ->assertOk();

        expect($device->fresh()->expires_at->equalTo(now()->addDay()))->toBeTrue();
    });

    it('ends the trust of an access token with the token, but keeps a remembered device', function (bool $rememberDevice, int $devicesLeft): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $token = (string) $this->passChallenge($this->loginForChallenge($user), [
            'code' => $this->twoFactorCode($user),
            'remember_device' => $rememberDevice,
        ])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent()->assertMatchesOpenApi('authentication.logout');

        expect(TrustedDevice::count())->toBe($devicesLeft)
            ->and(TrustedDevice::whereNotNull('access_token_id')->count())->toBe(0);
    })->with([
        'trust for the token only' => [false, 0],
        'a remembered device' => [true, 1],
    ]);

    it('never remembers a device after a recovery code', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $recoveryCode = $this->recoveryCode($user);

        $response = $this->passChallenge($this->loginForChallenge($user), ['recovery_code' => $recoveryCode, 'remember_device' => true]);

        $response->assertOk()->assertJson(['device_token' => null]);
        expect(TrustedDevice::sole()->token_hash)->toBeNull()
            ->and($user->fresh()->recoveryCodes())->not->toContain($recoveryCode);
    });

    it('trusts only the session after a recovery code in session mode', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertMatchesOpenApi('authentication.login');

        $response = $this->fromSpa()->postJson('/api/auth/two-factor-challenge', ['recovery_code' => $this->recoveryCode($user), 'remember_device' => true]);

        $response->assertNoContent()->assertCookieMissing('two_factor_device')->assertMatchesOpenApi('authentication.two-factor.challenge');
        expect(TrustedDevice::count())->toBe(0);
        $this->fromSpa()->getJson('/protected-area')->assertOk();
    });
});
