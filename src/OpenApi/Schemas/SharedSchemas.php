<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Schemas;

use Closure;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Http\ErrorCode;

/**
 * The schemas and responses the package's operations share. Shared ones are
 * registered once as components and referenced.
 */
final class SharedSchemas
{
    public function __construct(private readonly Components $components) {}

    /**
     * The authenticated user, as the default user resource returns it.
     */
    public function authenticatable(): Reference
    {
        if (! $this->components->hasSchema('Authenticatable')) {
            $id = (new AnyOf)->setItems([new IntegerType, new StringType]);

            $this->components->addSchema('Authenticatable', Schema::fromType(
                (new ObjectType)
                    ->addProperty('id', $id)
                    ->addProperty('email', (new StringType)->format('email'))
                    ->addProperty('confirmation_method', (new StringType)
                        ->enum([AuthenticationMethod::Password->value, AuthenticationMethod::LoginCode->value])
                        ->nullable(true)
                        ->setDescription('How the user confirms their identity on POST /user/confirm-password: with their password, or with an emailed code from POST /user/confirm-password/code. Null when they can\'t.'))
                    ->setRequired(['id', 'email', 'confirmation_method']),
            ));
        }

        return $this->components->getSchemaReference('Authenticatable');
    }

    /**
     * A JSON object with a "message" string.
     */
    public function message(int $status, string $description): Response
    {
        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($this->messageObject()));
    }

    /**
     * A response without a body worth reading.
     */
    public function empty(int $status, string $description): Response
    {
        return Response::make($status)->setDescription($description);
    }

    /**
     * 401: the request is not authenticated.
     */
    public function unauthenticated(): Reference
    {
        return $this->response('Unauthenticated', fn (): Response => $this->message(401, 'Unauthenticated'));
    }

    /**
     * 403: a package error with a stable "code".
     */
    public function forbidden(ErrorCode $code, string $description): Response
    {
        return Response::make(403)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType(
                $this->messageObject()
                    ->addProperty('code', (new StringType)->enum([$code->value]))
                    ->setRequired(['message', 'code']),
            ));
    }

    /**
     * 422: validation failed, or the credentials were wrong.
     */
    public function validationError(): Reference
    {
        return $this->response('ValidationError', function (): Response {
            $errors = (new ObjectType)
                ->additionalProperties((new ArrayType)->setItems(new StringType))
                ->setDescription('The messages per invalid field.');

            return Response::make(422)
                ->setDescription('Validation error')
                ->setContent('application/json', Schema::fromType(
                    $this->messageObject()->addProperty('errors', $errors)->setRequired(['message', 'errors']),
                ));
        });
    }

    /**
     * 423: the route needs a recent password confirmation.
     */
    public function passwordConfirmationRequired(): Reference
    {
        return $this->response(
            'PasswordConfirmationRequired',
            fn (): Response => $this->message(423, 'Password confirmation required: confirm the password, then retry.'),
        );
    }

    /**
     * 429: too many attempts.
     */
    public function tooManyRequests(): Reference
    {
        return $this->response('TooManyRequests', fn (): Response => $this->message(429, 'Too many attempts'));
    }

    private function messageObject(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('message', new StringType)
            ->setRequired(['message']);
    }

    /**
     * Register a response component once and reference it.
     *
     * @param  Closure(): Response  $build
     */
    private function response(string $name, Closure $build): Reference
    {
        $reference = new Reference('responses', $name, $this->components);

        if (! $this->components->has($reference)) {
            $this->components->add($reference, $build());
        }

        return $reference;
    }
}
