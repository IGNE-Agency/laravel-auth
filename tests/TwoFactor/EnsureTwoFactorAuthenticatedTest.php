<?php

declare(strict_types=1);

use Igne\Auth\Http\Middleware\AcceptJson;
use Igne\Auth\Tests\Fixtures\User;
use Igne\Auth\TwoFactor\Http\Middleware\EnsureTwoFactorAuthenticated;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([AcceptJson::class, 'api', 'auth:sanctum'])->group(function (): void {
        Route::get('api-area', fn (): array => ['area' => 'api']);
        Route::get('open-area', fn (): array => ['area' => 'open'])->withoutMiddleware(EnsureTwoFactorAuthenticated::class);
        Route::get('throttled-area', fn (): array => ['area' => 'throttled'])->middleware('throttle:60,1')->name('throttled-area');
    });
    Route::middleware('web')->get('web-area', fn (): array => ['area' => 'web']);
    Route::middleware([AcceptJson::class, 'api'])->get('public-area', fn (): array => ['area' => 'public']);
});

describe('a user who must set up two-factor authentication', function (): void {
    beforeEach(function (): void {
        config(['authentication.two_factor.required' => true]);
    });

    it('gets "two_factor_setup_required" on app API routes', function (): void {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api-area');

        $response->assertForbidden()->assertExactJson([
            'message' => 'Set up two-factor authentication to continue.',
            'code' => 'two_factor_setup_required',
        ]);
    });

    it('gets "two_factor_setup_required" on package routes', function (): void {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)->putJson('/api/user/password', ['current_password' => 'password']);

        $response->assertForbidden()->assertExactJson([
            'message' => 'Set up two-factor authentication to continue.',
            'code' => 'two_factor_setup_required',
        ])->assertMatchesOpenApi('authentication.user-password.update');
    });

    it('gets "two_factor_setup_required" on web routes', function (): void {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/web-area');

        $response->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
    });

    it('can still reach the user, logout and the two-factor setup', function (): void {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);

        $currentUser = $this->getJson('/api/user');
        $confirmation = $this->postJson('/api/user/confirm-password', ['password' => 'password']);
        $setup = $this->postJson('/api/user/two-factor-authentication');
        $qrCode = $this->getJson('/api/user/two-factor-qr-code');
        $logout = $this->postJson('/api/auth/logout');

        $currentUser->assertOk()->assertMatchesOpenApi('authentication.user');
        $confirmation->assertCreated()->assertMatchesOpenApi('authentication.password.confirm.store');
        $setup->assertOk()->assertMatchesOpenApi('authentication.two-factor.enable');
        $qrCode->assertOk()->assertJsonStructure(['svg', 'url'])->assertMatchesOpenApi('authentication.two-factor.qr-code');
        $logout->assertNoContent()->assertMatchesOpenApi('authentication.logout');
    });

    it('is trusted on the access token it confirms the setup with', function (): void {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $this->postJson('/api/user/confirm-password', ['password' => 'password'])->assertCreated()->assertMatchesOpenApi('authentication.password.confirm.store');
        $this->postJson('/api/user/two-factor-authentication')->assertOk()->assertMatchesOpenApi('authentication.two-factor.enable');

        $confirmation = $this->postJson('/api/user/confirmed-two-factor-authentication', ['code' => $this->twoFactorCode($user->fresh())]);
        $this->forgetAuthenticatedUsers();
        $response = $this->getJson('/api-area');

        $confirmation->assertOk()->assertMatchesOpenApi('authentication.two-factor.confirm');
        $response->assertOk();
    });
});

it('lets a user without two-factor authentication through when it is not required', function (): void {
    $user = User::factory()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api-area');

    $response->assertOk();
});

it('gives a user with two-factor authentication on an untrusted access token "two_factor_required"', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api-area');

    $response->assertForbidden()->assertExactJson([
        'message' => 'Confirm it is you with the code from your authenticator app to continue.',
        'code' => 'two_factor_required',
    ]);
});

it('keeps two-factor management behind the challenge on an untrusted access token', function (): void {
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->deleteJson('/api/user/two-factor-authentication');

    $response->assertForbidden()->assertJsonPath('code', 'two_factor_required')->assertMatchesOpenApi('authentication.two-factor.disable');
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

describe('step-up', function (): void {
    it('lets a bearer client pass the challenge again with its access token', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $token = $user->createToken('test')->plainTextToken;

        $before = $this->withToken($token)->getJson('/api-area');
        $stepUp = $this->withToken($token)->postJson('/api/auth/two-factor-challenge', ['code' => $this->twoFactorCode($user)]);
        $this->forgetAuthenticatedUsers();
        $after = $this->withToken($token)->getJson('/api-area');

        $before->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $stepUp->assertOk()->assertExactJson(['device_token' => null])->assertMatchesOpenApi('authentication.two-factor.challenge');
        $after->assertOk();
        expect($user->tokens()->count())->toBe(1);
    });

    it('lets an SPA pass the challenge again in its session', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $this->actingAs($user, 'web');

        $before = $this->fromSpa()->getJson('/api-area');
        $stepUp = $this->fromSpa()->postJson('/api/auth/two-factor-challenge', ['code' => $this->twoFactorCode($user)]);
        $after = $this->fromSpa()->getJson('/api-area');

        $before->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $stepUp->assertNoContent()->assertMatchesOpenApi('authentication.two-factor.challenge');
        $after->assertOk();
    });

    it('rejects a wrong step-up code', function (): void {
        $user = User::factory()->withTwoFactorAuthentication()->create();
        $token = $user->createToken('test')->plainTextToken;

        $stepUp = $this->withToken($token)->postJson('/api/auth/two-factor-challenge', ['code' => '000000']);
        $this->forgetAuthenticatedUsers();
        $after = $this->withToken($token)->getJson('/api-area');

        $stepUp->assertUnprocessable()->assertJsonValidationErrorFor('code')->assertMatchesOpenApi('authentication.two-factor.challenge');
        $after->assertForbidden();
    });
});

it('checks nothing without Fortify\'s two-factor feature', function (): void {
    config(['authentication.two_factor.required' => true, 'fortify.features' => []]);
    $user = User::factory()->withTwoFactorAuthentication()->create();

    $response = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/api-area');

    $response->assertOk();
});

it('lets guests and routes without the middleware through', function (): void {
    config(['authentication.two_factor.required' => true]);
    $user = User::factory()->create();

    $guest = $this->getJson('/public-area');
    $withoutMiddleware = $this->withToken($user->createToken('test')->plainTextToken)->getJson('/open-area');

    $guest->assertOk();
    $withoutMiddleware->assertOk();
});

it('skips the routes listed in "except" and the setup check for those in "except_setup"', function (): void {
    config([
        'authentication.two_factor.required' => true,
        'authentication.two_factor.except' => ['api-area'],
        'authentication.two_factor.except_setup' => ['throttled-area'],
    ]);
    $user = User::factory()->create();
    $this->withToken($user->createToken('test')->plainTextToken);

    $exempt = $this->getJson('/api-area');
    $exemptFromSetup = $this->getJson('/throttled-area');

    $exempt->assertOk();
    $exemptFromSetup->assertOk();
});

it('runs right after authentication and before throttling', function (): void {
    $router = app(Router::class);
    $router->getRoutes()->refreshNameLookups();
    $route = $router->getRoutes()->getByName('throttled-area');

    $middleware = array_map(fn (mixed $middleware): string => is_string($middleware) ? explode(':', $middleware)[0] : '', $router->gatherRouteMiddleware($route));
    $position = fn (string $class): int|false => array_search($class, $middleware, true);

    expect($position(EnsureTwoFactorAuthenticated::class))->toBe($position(Authenticate::class) + 1)
        ->and($position(EnsureTwoFactorAuthenticated::class))->toBeLessThan($position(ThrottleRequests::class));
});
