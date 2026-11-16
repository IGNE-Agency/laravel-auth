<?php

declare(strict_types=1);

use Igne\Auth\AuthenticationServiceProvider;
use Illuminate\Support\ServiceProvider;

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
