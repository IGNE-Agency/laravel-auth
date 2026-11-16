<?php

declare(strict_types=1);

use Igne\Auth\AuthenticationServiceProvider;
use Igne\Auth\Http\Responses\LoginResponse;
use Igne\Auth\Http\Responses\LogoutResponse;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

it('is registered with the app', function (): void {
    expect(app()->providerIsLoaded(AuthenticationServiceProvider::class))->toBeTrue();
});

it('merges its route defaults into the app config', function (): void {
    expect(config('authentication.routes'))->toBe([
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['api'],
    ]);
});

it('publishes its config under the authentication-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(AuthenticationServiceProvider::class, 'authentication-config'))->toBe([
        dirname(__DIR__).'/config/authentication.php' => config_path('authentication.php'),
    ]);
});

it('binds its responses although Fortify registers after the package', function () {
    expect(app(LoginResponseContract::class))->toBeInstanceOf(LoginResponse::class)
        ->and(app(LogoutResponseContract::class))->toBeInstanceOf(LogoutResponse::class);
});

it('prunes expired Sanctum tokens daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired --hours=24'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

it('schedules nothing when the schedule is off', function () {
    config(['authentication.schedule' => false]);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'sanctum:prune-expired'));

    expect($events)->toBeEmpty();
});
