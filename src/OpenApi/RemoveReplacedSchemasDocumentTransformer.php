<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;

/**
 * Removes the component schemas Scramble inferred for the package routes
 * before their explicit schemas replaced them, unless something else still
 * references them.
 */
final class RemoveReplacedSchemasDocumentTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $replacedNames = collect($document->paths)
            ->flatMap(fn (Path $path): array => array_values($path->operations))
            ->flatMap(fn (Operation $operation): array => $operation->getAttribute(AuthenticationOperationTransformer::REPLACED_SCHEMAS_ATTRIBUTE, []))
            ->unique();

        if ($replacedNames->isEmpty()) {
            return;
        }

        $serializedDocument = (string) json_encode($document->serializeAs31(), JSON_UNESCAPED_SLASHES);

        foreach (array_keys($document->components->schemas) as $fullName) {
            $name = $document->components->uniqueSchemaName($fullName);

            if ($replacedNames->contains($name) && ! str_contains($serializedDocument, "\"#/components/schemas/{$name}\"")) {
                $document->components->removeSchema($fullName);
            }
        }
    }
}
