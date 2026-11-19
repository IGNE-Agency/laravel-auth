<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Scramble;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Igne\Auth\Http\Channel;
use Igne\Auth\OpenApi\Schemas\AuthenticationMode;

/**
 * Turns the document into the document of one authentication mode: it leaves
 * out the security schemes, security requirements, parameters, responses and
 * properties marked with the other mode ({@see AuthenticationMode}), and gives
 * the package's operations their description for this mode. Parts without a
 * mark stay as they are.
 */
final readonly class AuthenticationModeDocumentTransformer implements DocumentTransformer
{
    public function __construct(private Channel $mode) {}

    /**
     * Scramble only accepts a configured transformer instance as a callable.
     */
    public function __invoke(OpenApi $document, OpenApiContext $context): void
    {
        $this->handle($document, $context);
    }

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $components = $document->components;
        $removedSchemes = array_keys(array_filter($components->securitySchemes, $this->isOtherMode(...)));
        $components->securitySchemes = array_diff_key($components->securitySchemes, array_flip($removedSchemes));

        foreach ($components->schemas as $schema) {
            if ($schema instanceof Schema) {
                $this->removeFromSchema($schema);
            }
        }

        foreach ($components->responses as $response) {
            if ($response instanceof Response) {
                $this->removeFromContent($response);
            }
        }

        foreach ($document->paths as $path) {
            $this->removeFromPath($path, $removedSchemes);
        }
    }

    /**
     * @param  list<string>  $removedSchemes
     */
    private function removeFromPath(Path $path, array $removedSchemes): void
    {
        foreach ($path->operations as $operation) {
            $this->describe($operation);

            if ($operation->security !== null) {
                $operation->security = array_values(array_filter(
                    $operation->security,
                    fn (SecurityRequirement $requirement): bool => array_intersect(array_keys((array) $requirement->toArray()), $removedSchemes) === [],
                ));
            }

            $operation->parameters = array_values(array_filter(
                $operation->parameters,
                fn (Parameter|Reference $parameter): bool => ! $parameter instanceof Parameter || ! $this->isOtherMode($parameter),
            ));

            if ($operation->requestBodyObject instanceof RequestBodyObject) {
                $this->removeFromContent($operation->requestBodyObject);
            }

            $operation->responses = array_values(array_filter(
                $operation->responses ?? [],
                fn (Response|Reference $response): bool => ! $response instanceof Response || ! $this->isOtherMode($response),
            ));

            foreach ($operation->responses as $response) {
                if ($response instanceof Response) {
                    $this->removeFromContent($response);
                }
            }
        }
    }

    /**
     * Give a package operation its description for this mode.
     */
    private function describe(Operation $operation): void
    {
        $descriptions = $operation->getAttribute(AuthenticationOperationTransformer::MODE_DESCRIPTIONS_ATTRIBUTE);

        if (is_array($descriptions) && is_string($descriptions[$this->mode->value] ?? null)) {
            $operation->description($descriptions[$this->mode->value]);
        }
    }

    private function removeFromContent(Response|RequestBodyObject $part): void
    {
        foreach ($part->content as $content) {
            $schema = $content instanceof MediaType ? $content->schema : $content;

            if ($schema instanceof Schema) {
                $this->removeFromSchema($schema);
            }
        }
    }

    private function removeFromSchema(Schema $schema): void
    {
        $this->removeFromType($schema->type);
    }

    private function removeFromType(Type $type): void
    {
        if ($type instanceof ArrayType) {
            $this->removeFromType($type->items);

            return;
        }

        if (! $type instanceof ObjectType) {
            return;
        }

        foreach ($type->properties as $name => $property) {
            if ($property === null) {
                continue;
            }

            if ($this->isOtherMode($property)) {
                unset($type->properties[$name]);
                $type->required = array_values(array_diff($type->required, [$name]));

                continue;
            }

            $this->removeFromType($property);
        }
    }

    private function isOtherMode(Type|Parameter|Response|SecurityScheme $part): bool
    {
        $mode = AuthenticationMode::of($part);

        return $mode !== null && $mode !== $this->mode;
    }
}
