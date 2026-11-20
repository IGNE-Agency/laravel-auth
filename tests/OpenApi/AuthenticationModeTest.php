<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Igne\Auth\Http\Channel;
use Igne\Auth\OpenApi\Scramble\AuthenticationModeDocumentTransformer;

/**
 * Generate the document of the default API, limited to the mode.
 *
 * @return array<string, mixed>
 */
function documentFor(Channel $mode): array
{
    Scramble::registerApi($mode->value)->withDocumentTransformers(new AuthenticationModeDocumentTransformer($mode));

    return app(Generator::class)(Scramble::getGeneratorConfig($mode->value));
}

it('leaves the token parts out of the session mode document', function (): void {
    $document = documentFor(Channel::Session);
    $login = $document['paths']['/auth/login']['post'];
    $challenge = $document['paths']['/auth/two-factor-challenge']['post'];

    expect(array_keys($login['responses'][200]['content']['application/json']['schema']['properties']))->toBe(['two_factor'])
        ->and(array_keys($login['requestBody']['content']['application/json']['schema']['properties']))->toBe(['email', 'password', 'remember'])
        ->and($login['parameters'] ?? [])->toBe([])
        ->and($login['description'])->toContain('cookie session')->not->toContain('access token')
        ->and($challenge['responses'])->toHaveKey(204)->not->toHaveKey(200)
        ->and($challenge['parameters'] ?? [])->toBe([])
        ->and(array_keys($document['components']['securitySchemes']))->toBe(['session', 'xsrf'])
        ->and($document['paths']['/user']['get']['security'])->toBe([['session' => [], 'xsrf' => []]]);
})->skip(! class_exists(Scramble::class), 'Scramble is not installed.');

it('leaves the session parts out of the token mode document', function (): void {
    $document = documentFor(Channel::Token);
    $login = $document['paths']['/auth/login']['post'];
    $challenge = $document['paths']['/auth/two-factor-challenge']['post'];

    expect(array_keys($login['responses'][200]['content']['application/json']['schema']['properties']))->toBe(['two_factor', 'challenge', 'token', 'expires_at'])
        ->and(array_keys($login['requestBody']['content']['application/json']['schema']['properties']))->toBe(['email', 'password', 'device_name'])
        ->and(array_column($login['parameters'], 'name'))->toBe(['X-Two-Factor-Device'])
        ->and($login['description'])->toContain('access token')->not->toContain('cookie session')
        ->and($challenge['responses'])->toHaveKey(200)->not->toHaveKey(204)
        ->and(array_column($challenge['parameters'], 'name'))->toBe(['X-Auth-Challenge'])
        ->and(array_keys($document['components']['securitySchemes']))->toBe(['bearer'])
        ->and($document['paths']['/user']['get']['security'])->toBe([['bearer' => []]]);
})->skip(! class_exists(Scramble::class), 'Scramble is not installed.');

it('documents an operation without mode-specific parts the same in both modes', function (): void {
    $sessionDocument = documentFor(Channel::Session);
    $tokenDocument = documentFor(Channel::Token);

    expect(json_encode($sessionDocument['paths']['/auth/login-code']['post']))->toBe(json_encode($tokenDocument['paths']['/auth/login-code']['post']));
})->skip(! class_exists(Scramble::class), 'Scramble is not installed.');
