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
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Http\Channel;
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
                        ->enum([AuthenticationMethod::Password->value, AuthenticationMethod::Totp->value, AuthenticationMethod::LoginCode->value])
                        ->nullable(true)
                        ->setDescription('How the user confirms their identity on POST /user/confirm-password: with their password, with a code from their authenticator app, or with an emailed code from POST /user/confirm-password/code. Null when they can\'t.'))
                    ->addProperty('two_factor_enabled', (new BooleanType)->setDescription('Whether the user set up two-factor authentication.'))
                    ->addProperty('two_factor_required', (new BooleanType)->setDescription('Whether the user must set up two-factor authentication before they can use the app.'))
                    ->setRequired(['id', 'email', 'confirmation_method', 'two_factor_enabled', 'two_factor_required']),
            ));
        }

        return $this->components->getSchemaReference('Authenticatable');
    }

    /**
     * A trusted device, as the device list returns it.
     */
    public function trustedDevice(): Reference
    {
        if (! $this->components->hasSchema('TrustedDevice')) {
            $this->components->addSchema('TrustedDevice', Schema::fromType(
                (new ObjectType)
                    ->addProperty('id', (new StringType)->setDescription('The device id for DELETE /user/devices/{trustedDevice}.'))
                    ->addProperty('name', (new StringType)->setDescription('The "device_name" given at login, or the user agent.'))
                    ->addProperty('ip_address', (new StringType)->nullable(true)->setDescription('The IP address the device passed the two-factor challenge from.'))
                    ->addProperty('remembered', (new BooleanType)->setDescription('Whether the user asked to remember the device ("remember_device").'))
                    ->addProperty('last_used_at', (new StringType)->format('date-time')->nullable(true))
                    ->addProperty('expires_at', (new StringType)->format('date-time')->nullable(true)->setDescription('When the trust ends; null when it ends with the access token.'))
                    ->addProperty('is_current', (new BooleanType)->setDescription('Whether the request comes from this device.'))
                    ->setRequired(['id', 'name', 'ip_address', 'remembered', 'last_used_at', 'expires_at', 'is_current']),
            ));
        }

        return $this->components->getSchemaReference('TrustedDevice');
    }

    /**
     * A web session or access token, as the session list returns it.
     */
    public function activeSession(): Reference
    {
        if (! $this->components->hasSchema('ActiveSession')) {
            $this->components->addSchema('ActiveSession', Schema::fromType(
                (new ObjectType)
                    ->addProperty('id', (new StringType)->setDescription('"session:" plus the SHA-256 hash of the session id, or "token:" plus the access token id; for DELETE /user/sessions/{session}.'))
                    ->addProperty('type', (new StringType)->enum([Channel::Session->value, Channel::Token->value])->setDescription('"session" for a cookie session, "token" for an access token.'))
                    ->addProperty('name', (new StringType)->setDescription('The user agent of a session, or the name of an access token.'))
                    ->addProperty('ip_address', (new StringType)->nullable(true)->setDescription('The last IP address of a session; null for an access token.'))
                    ->addProperty('last_active_at', (new StringType)->format('date-time')->nullable(true))
                    ->addProperty('is_current', (new BooleanType)->setDescription('Whether the request comes from this session or access token.'))
                    ->setRequired(['id', 'type', 'name', 'ip_address', 'last_active_at', 'is_current']),
            ));
        }

        return $this->components->getSchemaReference('ActiveSession');
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
     * 403: package errors with a stable "code".
     *
     * @param  non-empty-list<ErrorCode>  $codes
     */
    public function forbidden(array $codes): Response
    {
        $description = implode(' ', array_map(fn (ErrorCode $code): string => "\"{$code->value}\": {$this->describe($code)}.", $codes));

        return Response::make(403)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType(
                $this->messageObject()
                    ->addProperty('code', (new StringType)->enum(array_map(fn (ErrorCode $code): string => $code->value, $codes)))
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

    private function describe(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::PasswordUnavailable => 'the user does not log in with a password',
            ErrorCode::LoginCodeUnavailable => 'the user confirms their identity with another method',
            ErrorCode::TwoFactorSetupRequired => 'the user must set up two-factor authentication first (POST /user/two-factor-authentication)',
            ErrorCode::TwoFactorRequired => 'the user must pass the two-factor challenge on this device first (POST /auth/two-factor-challenge)',
            ErrorCode::CurrentSession => 'the request comes from this session or access token; log out to end it (POST /auth/logout)',
        };
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
