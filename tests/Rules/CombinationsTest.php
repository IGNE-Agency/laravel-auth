<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Igne\Auth\Tests\Fixtures\User;
use Igne\ErrorContract\ErrorContractServiceProvider;
use Igne\ApiDocs\ApiDocsServiceProvider;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

// The package with and without the packages an app may add next to it. None of them is a dependency: they are
// only installed for these tests. One and the same error is followed through every combination.

beforeEach(function (): void {
    config(['authentication.two_factor.required' => true, 'scramble.api_path' => 'api']);

    $this->refused = fn () => $this
        ->withToken(User::factory()->create()->createToken('test')->plainTextToken)
        ->getJson(route('authentication.sessions.index'));

    $this->documented = function (): array {
        $document = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
        $path = '/'.ltrim(str_replace('api/', '', collect(Route::getRoutes()->getRoutes())->first(fn (LaravelRoute $route): bool => $route->getName() === 'authentication.sessions.index')->uri()), '/');

        return [$document['paths'][$path]['get']['responses']['403'], $document['components']['schemas']];
    };
});

it('answers as Laravel does when the package is alone', function (): void {
    ($this->refused)()
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Set up two-factor authentication to continue.']);
});

it('answers in the shape of an error package, named after its class, without knowing that package', function (): void {
    $this->app->register(ErrorContractServiceProvider::class);

    ($this->refused)()
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'two_factor_setup_required')
        ->assertJsonPath('detail', 'Set up two-factor authentication to continue.');
});

it('is documented as Laravel answers when only a documentation package is added', function (): void {
    $this->app->register(ApiDocsServiceProvider::class);

    [$response, $schemas] = ($this->documented)();

    expect($response['content'])->toBe(['application/json' => ['schema' => ['$ref' => '#/components/schemas/ForbiddenError']]])
        ->and($schemas['ForbiddenError']['properties'])->toBe(['message' => ['type' => 'string']]);
});

it('is documented in the shape of the error package when both are added', function (): void {
    $this->app->register(ErrorContractServiceProvider::class);
    $this->app->register(ApiDocsServiceProvider::class);

    [$response, $schemas] = ($this->documented)();

    expect(array_keys($response['content']))->toBe(['application/problem+json'])
        ->and(array_keys($response['content']['application/problem+json']['schema']['discriminator']['mapping']))->toBe(['two_factor_required', 'two_factor_setup_required'])
        ->and($schemas['TwoFactorSetupRequiredError']['properties']['code'])->toBe(['type' => 'string', 'const' => 'two_factor_setup_required'])
        // The description is the docblock of the exception class.
        ->and($schemas['TwoFactorSetupRequiredError']['description'])->toBe('The user must set up two-factor authentication before they can continue.');
});
