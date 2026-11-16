<?php

declare(strict_types=1);

use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Notifications\TwoFactorAuthenticationResetNotification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Nova\Nova;

beforeEach(function (): void {
    config(['authentication.two_factor.required' => true]);
    $this->admin = User::factory()->create();
    Gate::define('viewNova', fn (User $user): bool => $user->is($this->admin));
});

it('walks an admin through setting up two-factor authentication, the challenge at login and again when trust lapses', function (): void {
    $credentials = ['email' => $this->admin->email, 'password' => 'password'];
    $usersPage = route('nova.pages.index', ['resource' => 'users']);

    // Without two-factor authentication, Nova sends the admin to User Security.
    $this->postJson(route('nova.login'), $credentials)->assertOk();
    $this->get($usersPage)->assertRedirect(route('nova.pages.user-security'));
    $this->get(route('nova.pages.user-security'))->assertOk();

    // The admin sets it up there, after confirming their password.
    $this->postJson($this->novaUrl('/user-security/two-factor-authentication'))->assertStatus(423);
    $this->postJson(route('nova.password.confirm'), ['password' => 'password'])->assertSuccessful();
    $this->postJson($this->novaUrl('/user-security/two-factor-authentication'))->assertOk();
    $this->getJson($this->novaUrl('/user-security/two-factor-qr-code'))->assertOk()->assertJsonStructure(['svg', 'url']);
    $this->postJson($this->novaUrl('/user-security/confirmed-two-factor-authentication'), ['code' => $this->twoFactorCode($this->admin->fresh())])->assertOk();
    $this->get($usersPage)->assertOk();

    // After logging out, the login asks for the challenge.
    $this->postJson(route('nova.logout'))->assertSuccessful();
    $this->postJson(route('nova.login'), $credentials)->assertOk()->assertExactJson(['two_factor' => true]);
    $this->assertGuest('web');
    $this->get(route('nova.two-factor.login'))->assertOk();
    $this->postJson($this->novaUrl('/user-security/two-factor-challenge'), ['code' => $this->twoFactorCode($this->admin->fresh(), periodsAhead: 1)])->assertOk();
    $this->get($usersPage)->assertOk();

    // When the trust lapses, the next visit restarts the login at the challenge.
    $this->travel(366)->days();
    $lapsed = $this->withHeader('X-Inertia', 'true')->get($usersPage);
    $this->flushHeaders();
    $lapsed->assertStatus(409)->assertHeader('X-Inertia-Location', route('nova.two-factor.login'));
    $this->assertGuest('web');
    $this->get(route('nova.two-factor.login'))->assertOk();
    $rechallenge = $this->postJson($this->novaUrl('/user-security/two-factor-challenge'), ['code' => $this->twoFactorCode($this->admin->fresh())]);
    $rechallenge->assertOk();
    expect(Str::lower($rechallenge->json('redirect')))->toBe(Str::lower($usersPage));
    $this->get($usersPage)->assertOk();
});

it('sends an admin who must set up two-factor authentication to User Security', function (array $headers, int $status): void {
    $this->actingAs($this->admin);

    $response = $this->withHeaders($headers)->get(route('nova.pages.index', ['resource' => 'users']));

    $response->assertStatus($status);
    expect($response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location') ?? $response->json('redirect'))
        ->toBe(route('nova.pages.user-security'));
})->with([
    'a page load' => [[], 302],
    'an Inertia visit' => [['X-Inertia' => 'true'], 409],
    'a call of Nova\'s HTTP client' => [['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'], 401],
]);

it('answers a Nova API call on an untrusted session with 401 and the challenge', function (): void {
    $admin = User::factory()->withTwoFactorAuthentication()->create();
    Gate::define('viewNova', fn (User $user): bool => $user->is($admin));
    $this->actingAs($admin);

    $response = $this->getJson($this->novaApiUrl('/users'));

    $response->assertUnauthorized()->assertExactJson(['redirect' => route('nova.two-factor.login')]);
    $this->assertGuest('web');
    $this->get(route('nova.two-factor.login'))->assertOk();
});

it('keeps the screens and assets an admin needs before two-factor authentication reachable', function (): void {
    $scriptPath = storage_path('framework/testing-two-factor.js');
    $stylePath = storage_path('framework/testing-two-factor.css');
    File::put($scriptPath, 'window.twoFactorTest = true;');
    File::put($stylePath, 'body {}');
    Nova::script('testing-two-factor', $scriptPath);
    Nova::style('testing-two-factor', $stylePath);
    $this->actingAs($this->admin);

    $userSecurity = $this->get(route('nova.pages.user-security'));
    $passwordStatus = $this->getJson(route('nova.password.confirmation'));
    $notifications = $this->getJson($this->novaApiUrl('/nova-notifications'));
    $script = $this->get($this->novaApiUrl('/scripts/testing-two-factor'));
    $style = $this->get($this->novaApiUrl('/styles/testing-two-factor'));
    File::delete([$scriptPath, $stylePath]);

    $userSecurity->assertOk();
    $passwordStatus->assertOk();
    $notifications->assertOk();
    $script->assertOk();
    $style->assertOk();
});

it('registers a password.confirm route that leads to Nova\'s password confirmation', function (): void {
    $response = $this->get(route('password.confirm'));

    $response->assertRedirect(Nova::url('/user-security/confirm-password'));
});

describe('the reset action', function (): void {
    beforeEach(function (): void {
        $this->admin->forceFill(User::factory()->withTwoFactorAuthentication()->make()->only(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']))->save();
        Gate::define('resetTwoFactorAuthentication', fn (User $user, User $target): bool => ! $user->is($target));
        $this->trustTwoFactorSession($this->admin);
        $this->actingAs($this->admin);
    });

    it('is offered for the users the gate allows', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $forOtherUser = $this->getJson($this->novaApiUrl('/users/actions').'?'.http_build_query(['resources' => [$user->id]]));
        $forSelf = $this->getJson($this->novaApiUrl('/users/actions').'?'.http_build_query(['resources' => [$this->admin->id]]));

        expect($forOtherUser->assertOk()->json('actions.*.uriKey'))->toContain('reset-two-factor-authentication')
            ->and($forSelf->assertOk()->json('actions.*.uriKey'))->not->toContain('reset-two-factor-authentication');
    });

    it('is a destructive action that asks for the admin\'s own password', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $response = $this->getJson($this->novaApiUrl('/users/actions').'?'.http_build_query(['resources' => [$user->id]]));

        $action = collect($response->assertOk()->json('actions'))->firstWhere('uriKey', 'reset-two-factor-authentication');
        expect($action['destructive'])->toBeTrue()
            ->and($action['confirmText'])->toContain('logged out on every device')
            ->and(collect($action['fields'])->pluck('attribute')->all())->toBe(['password']);
    });

    it('is not offered to a user who may not reset two-factor authentication', function (): void {
        Gate::define('resetTwoFactorAuthentication', fn (): bool => false);
        $user = User::factory()->create();

        $response = $this->getJson($this->novaApiUrl('/users/actions').'?'.http_build_query(['resources' => [$user->id]]));

        expect($response->assertOk()->json('actions.*.uriKey'))->not->toContain('reset-two-factor-authentication');
    });

    it('resets the two-factor authentication of the selected user and tells them who did', function (): void {
        Notification::fake();
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $response = $this->postJson($this->novaApiUrl('/users/action').'?action=reset-two-factor-authentication', ['resources' => (string) $user->id, 'password' => 'password']);

        $response->assertOk();
        expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
        Notification::assertSentTo($user, TwoFactorAuthenticationResetNotification::class, fn (TwoFactorAuthenticationResetNotification $notification): bool => $notification->resetBy?->is($this->admin) === true);
    });

    it('resets nothing without the admin\'s correct password', function (array $input): void {
        Notification::fake();
        $user = User::factory()->withTwoFactorAuthentication()->create();

        $response = $this->postJson($this->novaApiUrl('/users/action').'?action=reset-two-factor-authentication', ['resources' => (string) $user->id, ...$input]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['password']);
        expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
        Notification::assertNothingSent();
    })->with([
        'a wrong password' => [['password' => 'wrong-password']],
        'no password' => [[]],
    ]);
});
