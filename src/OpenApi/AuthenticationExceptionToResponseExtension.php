<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Igne\Auth\Http\ErrorCode;
use Igne\Auth\Http\Exceptions\AuthenticationException;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Documents the package exceptions an app's own code throws, as their
 * {message, code} response.
 */
final class AuthenticationExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(AuthenticationException::class);
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        $exception = $this->instantiate($type->name);
        $codes = $exception === null ? ErrorCode::cases() : [$exception->errorCode()];

        // The description comes from the code, not the translated message, so
        // the document does not depend on the locale.
        return Response::make($exception?->status() ?? 403)
            ->setDescription($exception === null ? 'Authentication error' : Str::headline($exception->errorCode()->name))
            ->setContent('application/json', Schema::fromType(
                (new OpenApiObjectType)
                    ->addProperty('message', new StringType)
                    ->addProperty('code', (new StringType)->enum(array_map(fn (ErrorCode $code): string => $code->value, $codes)))
                    ->setRequired(['message', 'code']),
            ));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', Str::start($type->name, '\\'), $this->components);
    }

    /**
     * Instantiate the exception to read its code and status, when it takes no
     * constructor arguments.
     */
    private function instantiate(string $class): ?AuthenticationException
    {
        if (! is_a($class, AuthenticationException::class, true)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            return null;
        }

        return $reflection->newInstance();
    }
}
