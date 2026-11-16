<?php

declare(strict_types=1);

use Igne\Auth\Tests\Support\OpenApiDocument;
use Igne\Auth\Tests\TestCase;
use Illuminate\Testing\TestResponse;

pest()->extend(TestCase::class)->in(__DIR__);

/*
 * Assert that the response is what the generated OpenAPI document promises
 * for the route: a documented status and, when documented, a JSON body that
 * matches the schema. Without Scramble or opis/json-schema it asserts nothing.
 */
TestResponse::macro('assertMatchesOpenApi', function (string $routeName): TestResponse {
    if (OpenApiDocument::isAvailable()) {
        OpenApiDocument::shared()->assertMatches($this, $routeName);
    }

    return $this;
});
