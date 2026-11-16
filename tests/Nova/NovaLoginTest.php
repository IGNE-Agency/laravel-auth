<?php

declare(strict_types=1);

use Igne\Auth\LoginCodes\Models\LoginCode;
use Igne\Auth\Tests\Fixtures\User;

it('logs a user into Nova with email and password through the package credential check', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('nova.login'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');
});

it('logs a user without a password into Nova with a login code, which only the package credential check accepts', function (): void {
    $user = User::factory()->withoutPassword()->create();
    LoginCode::factory()->for($user, 'authenticatable')->withCode('123456')->create();

    $response = $this->post(route('nova.login'), ['email' => $user->email, 'password' => '123456']);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects a wrong Nova password', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('nova.login'), ['email' => $user->email, 'password' => 'wrong']);

    $response->assertSessionHasErrors('email');
    $this->assertGuest('web');
});

it('logs a user out of Nova through the package logout response', function (): void {
    $user = User::factory()->create();
    $this->post(route('nova.login'), ['email' => $user->email, 'password' => 'password']);

    $response = $this->post(route('nova.logout'));

    $response->assertRedirect();
    $this->assertGuest('web');
});
