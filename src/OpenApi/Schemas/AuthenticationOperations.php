<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Schemas;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Igne\Auth\Http\ErrorCode;
use Laravel\Fortify\Fortify;

/**
 * The explicit request and response schemas of the package routes, by route
 * name. Scramble can't infer them: the routes run Fortify's controllers, which
 * answer through container-bound response classes.
 */
final class AuthenticationOperations
{
    public function __construct(private readonly SharedSchemas $schemas) {}

    public function for(string $routeName): ?OperationSchema
    {
        return match ($routeName) {
            'authentication.login' => $this->login(),
            'authentication.logout' => new OperationSchema(
                summary: 'Log out',
                description: 'Session mode ends the session. Token mode revokes the access token the request was sent with.',
                responses: [$this->schemas->empty(204, 'Logged out')],
            ),
            'authentication.login-code' => new OperationSchema(
                summary: 'Request a login code',
                description: 'Emails a one-time login code when the account can log in with one. The answer is always 202 with the same body: for unknown accounts, for accounts that can\'t use codes and when rate-limited too, so it never reveals whether an account exists. Log in by sending the code as "password" to POST /auth/login.',
                requestBody: $this->object([Fortify::username() => $this->email()]),
                responses: [$this->schemas->message(202, 'The request was accepted')],
                validatesInput: true,
                throttled: false,
            ),
            'authentication.password.email' => new OperationSchema(
                summary: 'Request a password reset link',
                description: 'Always answers with the same success for a valid email address, whether or not an account exists. Only users that can log in with a password get a link.',
                requestBody: $this->object(['email' => $this->email()]),
                responses: [$this->schemas->message(200, 'The request was accepted')],
                validatesInput: true,
                throttled: true,
            ),
            'authentication.password.update' => new OperationSchema(
                summary: 'Reset the password with a reset token',
                requestBody: $this->object([
                    'token' => new StringType,
                    'email' => $this->email(),
                    'password' => new StringType,
                    'password_confirmation' => new StringType,
                ]),
                responses: [$this->schemas->message(200, 'The password was reset')],
                validatesInput: true,
            ),
            'authentication.user' => new OperationSchema(
                summary: 'Get the authenticated user',
                responses: [
                    Response::make(200)
                        ->setDescription('The authenticated user')
                        ->setContent('application/json', Schema::fromType($this->schemas->authenticatable())),
                ],
            ),
            'authentication.user-password.update' => new OperationSchema(
                summary: 'Change the password',
                description: 'Only for users that log in with a password.',
                requestBody: $this->object([
                    'current_password' => new StringType,
                    'password' => new StringType,
                    'password_confirmation' => new StringType,
                ]),
                responses: [
                    $this->schemas->empty(200, 'The password was changed'),
                    $this->schemas->forbidden(ErrorCode::PasswordUnavailable, 'The user does not log in with a password'),
                ],
                validatesInput: true,
            ),
            'authentication.password.confirm.store' => new OperationSchema(
                summary: 'Confirm the password',
                description: 'Starts the password confirmation window that routes requiring a recent confirmation (423) check. Token mode keeps it per access token. "password" is what the user\'s "confirmation_method" says: their password, or an emailed code from POST /user/confirm-password/code.',
                requestBody: $this->object(['password' => new StringType]),
                responses: [$this->schemas->empty(201, 'The password was confirmed')],
                validatesInput: true,
            ),
            'authentication.password.confirmation' => new OperationSchema(
                summary: 'Get the password confirmation status',
                responses: [
                    Response::make(200)
                        ->setDescription('Whether the password was confirmed recently')
                        ->setContent('application/json', Schema::fromType(
                            (new ObjectType)->addProperty('confirmed', new BooleanType)->setRequired(['confirmed']),
                        )),
                ],
                parameters: [
                    Parameter::make('seconds', 'query')
                        ->description('The confirmation window in seconds. Defaults to the app\'s password timeout.')
                        ->setSchema(Schema::fromType(new IntegerType)),
                ],
            ),
            'authentication.password.confirm.code' => new OperationSchema(
                summary: 'Request a confirmation code',
                description: 'Emails a one-time code to a user whose "confirmation_method" is "login_code". Confirm by sending it as "password" to POST /user/confirm-password.',
                responses: [
                    $this->schemas->message(202, 'The code was sent'),
                    $this->schemas->forbidden(ErrorCode::LoginCodeUnavailable, 'The user confirms with another method'),
                ],
            ),
            default => null,
        };
    }

    private function login(): OperationSchema
    {
        $body = (new ObjectType)
            ->addProperty('two_factor', (new BooleanType)->setDescription('Whether a two-factor challenge must follow.'))
            ->addProperty('token', (new StringType)->setDescription('Token mode only: the access token for the Authorization: Bearer header.'))
            ->addProperty('expires_at', (new StringType)->format('date-time')->nullable(true)->setDescription('Token mode only: when the access token expires, or null when it does not.'))
            ->setRequired(['two_factor']);

        return new OperationSchema(
            summary: 'Log in with email and password or login code',
            description: 'Session mode (an SPA whose requests run through Sanctum\'s stateful stack) logs in to the cookie session. Token mode answers with an access token instead; "remember" is ignored and "device_name" names the token (the user agent otherwise). "password" is the user\'s password, or a login code from POST /auth/login-code for users that log in with codes.',
            requestBody: $this->object([
                Fortify::username() => $this->email(),
                'password' => new StringType,
                'remember' => new BooleanType,
                'device_name' => (new StringType)->setMax(255),
            ], required: [Fortify::username(), 'password']),
            responses: [
                Response::make(200)
                    ->setDescription('Logged in')
                    ->setContent('application/json', Schema::fromType($body)),
            ],
            validatesInput: true,
            throttled: true,
        );
    }

    private function email(): StringType
    {
        $email = new StringType;
        $email->format('email');

        return $email;
    }

    /**
     * @param  array<string, Type>  $properties
     * @param  list<string>|null  $required  all properties when null
     */
    private function object(array $properties, ?array $required = null): ObjectType
    {
        $object = new ObjectType;

        foreach ($properties as $name => $type) {
            $object->addProperty($name, $type);
        }

        return $object->setRequired($required ?? array_keys($properties));
    }
}
