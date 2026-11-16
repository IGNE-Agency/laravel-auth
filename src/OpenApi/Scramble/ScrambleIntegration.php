<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Scramble;

use Dedoc\Scramble\Configuration\GeneratorConfigCollection;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\Foundation\Application;

/**
 * Documents the package routes in the app's default Scramble API: explicit
 * schemas for every package route, the security schemes, and the responses of
 * the package exceptions an app's own code throws.
 */
final class ScrambleIntegration
{
    public function __construct(private readonly Application $app) {}

    public function boot(): void
    {
        Scramble::registerExtension(AuthenticationExceptionToResponseExtension::class);

        $this->app->afterResolving(GeneratorConfigCollection::class, static function (GeneratorConfigCollection $apis): void {
            self::documentDefaultApi($apis);
        });

        if ($this->app->resolved(GeneratorConfigCollection::class)) {
            self::documentDefaultApi($this->app->make(GeneratorConfigCollection::class));
        }
    }

    private static function documentDefaultApi(GeneratorConfigCollection $apis): void
    {
        $apis->get(Scramble::DEFAULT_API)
            ->withOperationTransformers(AuthenticationOperationTransformer::class)
            ->withDocumentTransformers([SecuritySchemesDocumentTransformer::class, RemoveReplacedSchemasDocumentTransformer::class]);
    }
}
