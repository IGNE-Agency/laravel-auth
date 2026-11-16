<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Scramble;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Igne\Auth\OpenApi\Schemas\AuthenticationOperations;
use Igne\Auth\OpenApi\Schemas\OperationSchema;
use Igne\Auth\OpenApi\Schemas\SharedSchemas;
use Illuminate\Support\Str;

/**
 * Documents the package routes with explicit schemas, and marks every route
 * behind "auth:sanctum" as authenticated by a bearer token or by the session
 * cookie plus the XSRF header.
 */
final class AuthenticationOperationTransformer implements OperationTransformer
{
    /**
     * The operation attribute that lists the component schemas the inferred
     * documentation referenced before the explicit schemas replaced it.
     */
    public const string REPLACED_SCHEMAS_ATTRIBUTE = 'authentication.replaced_schemas';

    public function __construct(private readonly OpenApi $openApi) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn (mixed $name): bool => is_string($name));

        if ($middleware->contains('auth:sanctum')) {
            $operation->security = [
                new SecurityRequirement([SecuritySchemesDocumentTransformer::BEARER => []]),
                new SecurityRequirement([SecuritySchemesDocumentTransformer::SESSION => [], SecuritySchemesDocumentTransformer::XSRF => []]),
            ];
        }

        $routeName = $routeInfo->route->getName();
        $schemas = new SharedSchemas($this->openApi->components);
        $definition = is_string($routeName) ? (new AuthenticationOperations($schemas))->for($routeName) : null;

        if ($definition === null) {
            return;
        }

        $operation->setAttribute(self::REPLACED_SCHEMAS_ATTRIBUTE, $this->referencedSchemaNames($operation));

        $operation->summary($definition->summary);
        $operation->description($definition->description);
        $operation->setTags(['Authentication']);
        $operation->parameters = [
            ...array_values(array_filter($operation->parameters, fn (Parameter|Reference $parameter): bool => $parameter instanceof Parameter && $parameter->in === 'path')),
            ...$definition->parameters,
        ];
        $operation->requestBodyObject = $definition->requestBody === null
            ? null
            : RequestBodyObject::make()->required()->setContent('application/json', Schema::fromType($definition->requestBody));
        $operation->responses = [
            ...$definition->responses,
            ...$this->errorResponses($definition, $middleware->all(), $schemas),
        ];
    }

    /**
     * Get the names of the component schemas the operation's request body and
     * responses reference.
     *
     * @return list<string>
     */
    private function referencedSchemaNames(Operation $operation): array
    {
        $serialized = (string) json_encode([
            $operation->requestBodyObject?->serializeAs31(),
            array_map(fn (Response|Reference $response): mixed => $response->serializeAs31(), $operation->responses ?? []),
        ], JSON_UNESCAPED_SLASHES);

        preg_match_all('~"#/components/schemas/([^"]+)"~', $serialized, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Get the error responses that follow from the route's middleware and
     * request body.
     *
     * @param  array<int|string, string>  $middleware
     * @return list<Reference>
     */
    private function errorResponses(OperationSchema $definition, array $middleware, SharedSchemas $schemas): array
    {
        $has = fn (string $pattern): bool => collect($middleware)->contains(fn (string $name): bool => Str::is($pattern, $name));

        return array_values(array_filter([
            $has('auth:*') ? $schemas->unauthenticated() : null,
            $definition->validatesInput ? $schemas->validationError() : null,
            $has('password.confirm*') ? $schemas->passwordConfirmationRequired() : null,
            ($definition->throttled ?? $has('throttle:*')) ? $schemas->tooManyRequests() : null,
        ]));
    }
}
