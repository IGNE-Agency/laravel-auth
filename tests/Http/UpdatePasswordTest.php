<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;
use Illuminate\Support\Facades\Hash;

it('changes the password of a bearer client', function (): void {
    $user = User::factory()->create();
    $token = $this->loginForToken($user);

    $response = $this->withToken($token)->putJson('/api/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertOk();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('checks the current password of a bearer client', function (): void {
    $user = User::factory()->create();
    $token = $this->loginForToken($user);

    $response = $this->withToken($token)->putJson('/api/user/password', [
        'current_password' => 'wrong',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password' => 'The password is incorrect.']);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('forbids a user without a password with 403', function (): void {
    $user = User::factory()->withoutPassword()->create();
    $token = $user->createToken('Laptop')->plainTextToken;

    $response = $this->withToken($token)->putJson('/api/user/password', [
        'current_password' => '',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertForbidden()->assertExactJson([
        'message' => 'Your account does not use a password.',
        'code' => 'password_unavailable',
    ]);
    expect($user->fresh()->password)->toBeNull();
});

it('rejects a new password that does not match its confirmation with 422', function (): void {
    $user = User::factory()->create();
    $token = $this->loginForToken($user);

    $response = $this->withToken($token)->putJson('/api/user/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'other-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['password']);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});
