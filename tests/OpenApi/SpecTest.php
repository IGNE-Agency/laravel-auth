<?php

declare(strict_types=1);

use Dedoc\Scramble\Scramble;
use Igne\Auth\Tests\Support\OpenApiDocument;

it('documents the package routes as in the snapshot', function (): void {
    $packageOwnedPart = OpenApiDocument::packageOwnedPart(OpenApiDocument::generate());

    expect(json_encode($packageOwnedPart, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->toMatchSnapshot();
})->skip(! class_exists(Scramble::class), 'Scramble is not installed.');
