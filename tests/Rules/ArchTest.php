<?php

declare(strict_types=1);

use Igne\Auth\TwoFactor\Exceptions\MissingTwoFactorAuthenticationException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorRequiredException;
use Igne\Auth\TwoFactor\Exceptions\TwoFactorSetupRequiredException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

// The rules that keep this package independent: it is plain Laravel, it throws its errors and it owns its routes.
// What shapes the errors of an app and what documents its API adapt to that; the package knows neither.

arch('the package uses no error package and no documentation tool')
    ->expect('Igne\Auth')
    ->not->toUse(['Dedoc\Scramble', 'Igne\ProblemDetails', 'Igne\ScrambleExtensions']);

arch('every error is a Symfony HTTP exception that does not render itself')
    ->expect(['Igne\Auth\Http\Exceptions', MissingTwoFactorAuthenticationException::class, TwoFactorRequiredException::class, TwoFactorSetupRequiredException::class])
    ->toExtend(HttpException::class)
    ->not->toImplement(Responsable::class)
    ->not->toHaveMethod('render');

it('keeps the name and the status of every error: both are public API', function (): void {
    $errors = [];

    foreach (glob(dirname(__DIR__, 2).'/src/{Http,TwoFactor}/Exceptions/*.php', GLOB_BRACE) ?: [] as $file) {
        $class = 'Igne\\Auth\\'.str_replace('/', '\\', substr($file, strlen(dirname(__DIR__, 2).'/src/'), -4));
        $reflection = new ReflectionClass($class);

        if ($reflection->isInstantiable() && $reflection->isSubclassOf(HttpException::class)) {
            $errors[$reflection->getShortName()] = $reflection->newInstance()->getStatusCode();
        }
    }

    ksort($errors);

    expect($errors)->toBe([
        'CurrentSessionException' => 403,
        'LoginCodeUnavailableException' => 403,
        'PasswordUnavailableException' => 403,
        'TwoFactorRequiredException' => 403,
        'TwoFactorSetupRequiredException' => 403,
    ]);
});

it('points every route at a controller of the package', function (): void {
    $foreign = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'authentication.'))
        ->reject(fn ($route): bool => str_starts_with(ltrim((string) $route->getControllerClass(), '\\'), 'Igne\\Auth\\'))
        ->map(fn ($route): string => (string) $route->getName())
        ->values();

    expect($foreign->all())->toBe([]);
});
