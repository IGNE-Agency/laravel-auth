<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;

it('sets up two-factor authentication with the secret key and the recovery codes', function (): void {
    $user = User::factory()->create();
    $this->withToken($user->createToken('test')->plainTextToken);
    $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

    $enable = $this->postJson('/api/user/two-factor-authentication');
    $secretKey = $this->getJson('/api/user/two-factor-secret-key');
    $recoveryCodes = $this->getJson('/api/user/two-factor-recovery-codes');
    $confirmation = $this->postJson('/api/user/confirmed-two-factor-authentication', ['code' => $this->twoFactorCode($user->fresh())]);

    $enable->assertOk();
    $secretKey->assertOk()->assertJsonStructure(['secretKey']);
    $recoveryCodes->assertOk()->assertJsonCount(8);
    $confirmation->assertOk();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('regenerates the recovery codes', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();
    $previousCodes = $user->recoveryCodes();
    $token = $this->passChallenge($this->loginForChallenge($user), ['code' => $this->twoFactorCode($user)])->assertOk()->json('token');
    $this->withToken((string) $token);
    $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated();

    $response = $this->postJson('/api/user/two-factor-recovery-codes');

    $response->assertOk();
    expect($user->fresh()->recoveryCodes())->toHaveCount(8)->not->toBe($previousCodes);
});
