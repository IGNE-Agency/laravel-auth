<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Support;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OpenAPI document Scramble generates for the app's default API, and the
 * check that a response is what that document promises for its route.
 *
 * Response schemas are validated as JSON Schema 2020-12, the dialect of
 * OpenAPI 3.1.
 */
final class OpenApiDocument
{
    /**
     * The URI the document is registered under, so its internal references resolve.
     */
    private const string ID = 'https://laravel-auth.test/openapi.json';

    private const string RESPONSE_REFERENCE_PREFIX = '#/components/responses/';

    private static ?self $shared = null;

    private function __construct(
        private readonly stdClass $document,
        private readonly Validator $validator,
    ) {}

    /**
     * Determine whether Scramble and the JSON Schema validator are installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists(Scramble::class) && class_exists(Validator::class);
    }

    /**
     * Generate the default API's document from the current app.
     *
     * @return array<string, mixed>
     */
    public static function generate(): array
    {
        return Container::getInstance()->make(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
    }

    /**
     * Get the document, generated once per process.
     */
    public static function shared(): self
    {
        if (self::$shared !== null) {
            return self::$shared;
        }

        $document = json_decode(json_encode(self::generate(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $validator = new Validator;
        $validator->setMaxErrors(5);
        $validator->parser()->setOption('defaultDraft', '2020-12');
        $validator->resolver()->registerRaw($document, self::ID);

        return self::$shared = new self($document, $validator);
    }

    /**
     * Get the part of a document the package owns: the operations of its
     * routes, and the components.
     *
     * @param  array<string, mixed>  $document
     * @return array{paths: array<string, array<string, mixed>>, components: mixed}
     */
    public static function packageOwnedPart(array $document): array
    {
        $paths = [];

        foreach (self::packageRoutes() as $route) {
            [$path, $method] = self::operationKey($route);

            Assert::assertArrayHasKey($path, $document['paths'], "The route [{$route->getName()}] is not documented.");

            $paths[$path][$method] = $document['paths'][$path][$method];
        }

        ksort($paths);

        return ['paths' => $paths, 'components' => $document['components'] ?? []];
    }

    /**
     * Assert that the response's status is documented for the route, and that
     * its JSON body matches the documented schema. A response documented
     * without a body is checked on its status only.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertMatches(TestResponse $response, string $routeName): void
    {
        [$path, $method] = self::operationKey(self::route($routeName));
        $status = (string) $response->getStatusCode();
        $operation = "[{$routeName}] (".strtoupper($method)." {$path})";

        $documentedResponses = $this->document->paths->{$path}->{$method}->responses ?? null;

        Assert::assertInstanceOf(stdClass::class, $documentedResponses, "The route {$operation} is not documented.");
        Assert::assertObjectHasProperty($status, $documentedResponses, "Status {$status} is not documented for {$operation}.");

        [$documentedResponse, $responsePointer] = $this->resolveResponse($documentedResponses->{$status}, $path, $method, $status);

        if (! isset($documentedResponse->content->{'application/json'}->schema)) {
            return;
        }

        try {
            $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Assert::fail("The {$status} response of {$operation} is not JSON: {$exception->getMessage()}");
        }

        $schemaPointer = $responsePointer.'/'.self::pointer('content', 'application/json', 'schema');
        $result = $this->validator->validate($body, (object) ['$ref' => self::ID.$schemaPointer]);

        Assert::assertTrue($result->isValid(), sprintf(
            "The %s response of %s does not match its schema:\n%s\n\nBody: %s",
            $status,
            $operation,
            json_encode($result->error() === null ? [] : (new ErrorFormatter)->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            Str::limit((string) $response->getContent(), 1000),
        ));
    }

    /**
     * Get the Response Object and its JSON pointer, following a reference to
     * a response component.
     *
     * @return array{stdClass, string}
     */
    private function resolveResponse(stdClass $documentedResponse, string $path, string $method, string $status): array
    {
        $reference = $documentedResponse->{'$ref'} ?? null;

        if (! is_string($reference)) {
            return [$documentedResponse, '#/'.self::pointer('paths', $path, $method, 'responses', $status)];
        }

        Assert::assertStringStartsWith(self::RESPONSE_REFERENCE_PREFIX, $reference);

        $name = substr($reference, strlen(self::RESPONSE_REFERENCE_PREFIX));

        return [$this->document->components->responses->{$name}, '#/'.self::pointer('components', 'responses', $name)];
    }

    /**
     * Get the routes the package registers.
     *
     * @return list<Route>
     */
    private static function packageRoutes(): array
    {
        $routes = Container::getInstance()->make(Router::class)->getRoutes()->getRoutes();

        return array_values(array_filter($routes, static fn (Route $route): bool => str_starts_with((string) $route->getName(), 'authentication.')));
    }

    private static function route(string $routeName): Route
    {
        $route = Container::getInstance()->make(Router::class)->getRoutes()->getByName($routeName);

        Assert::assertNotNull($route, "There is no route named [{$routeName}].");

        return $route;
    }

    /**
     * Get the path and method the route's operation has in the document: the
     * URI without Scramble's API path, and the method other than HEAD.
     *
     * @return array{string, string}
     */
    private static function operationKey(Route $route): array
    {
        $apiPath = trim(config()->string('scramble.api_path', 'api'), '/');
        $uri = str_replace('?}', '}', $route->uri());
        $path = '/'.ltrim(Str::chopStart($uri, $apiPath), '/');
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];

        return [$path, strtolower($method)];
    }

    /**
     * Join the segments into a JSON pointer fragment: each segment escaped,
     * then URL-encoded, so a path such as "/devices/{trustedDevice}" resolves.
     */
    private static function pointer(string ...$segments): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segment)),
            $segments,
        ));
    }
}
