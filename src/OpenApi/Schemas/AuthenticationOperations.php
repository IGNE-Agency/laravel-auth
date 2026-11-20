<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Schemas;

use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Igne\Auth\Http\Channel;
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
                responses: [$this->schemas->empty(204, 'Logged out')],
                sessionDescription: 'Ends the session.',
                tokenDescription: 'Revokes the access token the request was sent with.',
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
                responses: [$this->schemas->empty(200, 'The password was changed')],
                validatesInput: true,
                errorCodes: [ErrorCode::PasswordUnavailable],
            ),
            'authentication.password.confirm.store' => new OperationSchema(
                summary: 'Confirm the password',
                description: 'Starts the password confirmation window that routes requiring a recent confirmation (423) check. "password" is what the user\'s "confirmation_method" says: their password, a code from their authenticator app, or an emailed code from POST /user/confirm-password/code.',
                requestBody: $this->object(['password' => new StringType]),
                responses: [$this->schemas->empty(201, 'The password was confirmed')],
                validatesInput: true,
                sessionDescription: 'The window is kept in the session.',
                tokenDescription: 'The window is kept per access token.',
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
                responses: [$this->schemas->message(202, 'The code was sent')],
                errorCodes: [ErrorCode::LoginCodeUnavailable],
            ),
            'authentication.two-factor.challenge' => $this->twoFactorChallenge(),
            'authentication.two-factor.enable' => new OperationSchema(
                summary: 'Set up two-factor authentication',
                description: 'Creates a new authenticator secret and recovery codes. Show the QR code from GET /user/two-factor-qr-code, then confirm a code from the authenticator app with POST /user/confirmed-two-factor-authentication. "force" replaces an existing secret.',
                requestBody: $this->object(['force' => new BooleanType], required: []),
                responses: [$this->schemas->empty(200, 'The secret was created')],
            ),
            'authentication.two-factor.confirm' => new OperationSchema(
                summary: 'Confirm two-factor authentication',
                description: 'Finishes the setup with a code from the authenticator app.',
                requestBody: $this->object(['code' => new StringType]),
                responses: [$this->schemas->empty(200, 'Two-factor authentication is set up')],
                validatesInput: true,
                sessionDescription: 'The session the request is sent with is trusted from then on.',
                tokenDescription: 'The access token the request is sent with is trusted from then on.',
            ),
            'authentication.two-factor.disable' => new OperationSchema(
                summary: 'Disable two-factor authentication',
                description: 'Removes the secret and recovery codes, and revokes the other trusted devices.',
                responses: [$this->schemas->empty(200, 'Two-factor authentication is disabled')],
            ),
            'authentication.two-factor.qr-code' => new OperationSchema(
                summary: 'Get the QR code of the authenticator secret',
                responses: [
                    Response::make(200)
                        ->setDescription('The QR code as SVG and its otpauth URL; an empty array before the setup started')
                        ->setContent('application/json', Schema::fromType($this->object(['svg' => new StringType, 'url' => new StringType]))),
                ],
            ),
            'authentication.two-factor.secret-key' => new OperationSchema(
                summary: 'Get the authenticator secret',
                description: 'For entering the secret in an authenticator app by hand.',
                responses: [
                    Response::make(200)
                        ->setDescription('The secret')
                        ->setContent('application/json', Schema::fromType($this->object(['secretKey' => new StringType]))),
                    $this->schemas->message(404, 'Two-factor authentication is not set up'),
                ],
            ),
            'authentication.two-factor.recovery-codes' => new OperationSchema(
                summary: 'Get the recovery codes',
                responses: [
                    Response::make(200)
                        ->setDescription('The unused recovery codes')
                        ->setContent('application/json', Schema::fromType((new ArrayType)->setItems(new StringType))),
                ],
            ),
            'authentication.two-factor.regenerate-recovery-codes' => new OperationSchema(
                summary: 'Replace the recovery codes',
                responses: [$this->schemas->empty(200, 'New recovery codes were made')],
            ),
            'authentication.devices.index' => new OperationSchema(
                summary: 'List the trusted devices',
                description: 'The devices and access tokens the user passed the two-factor challenge on, that are still trusted, newest first.',
                responses: [$this->list('The trusted devices', $this->schemas->trustedDevice())],
            ),
            'authentication.devices.destroy' => new OperationSchema(
                summary: 'Revoke a trusted device',
                description: 'The device must pass the two-factor challenge again at its next login; when the request comes from it, at its next request.',
                responses: [
                    $this->schemas->empty(204, 'The device is no longer trusted'),
                    $this->schemas->message(404, 'The user has no such device'),
                ],
                parameters: [
                    Parameter::make('trustedDevice', 'path')
                        ->description('The "id" from GET /user/devices.')
                        ->required(true)
                        ->setSchema(Schema::fromType(new StringType)),
                ],
            ),
            'authentication.sessions.index' => new OperationSchema(
                summary: 'List the sessions',
                description: 'The places the user is logged in: cookie sessions (only with the "database" session driver) and access tokens. The one the request comes from is first, then the most recently active.',
                responses: [$this->list('The sessions and access tokens', $this->schemas->activeSession())],
            ),
            'authentication.sessions.destroy' => new OperationSchema(
                summary: 'Revoke a session',
                description: 'Ends a cookie session or revokes an access token. Ending a cookie session also ends "remember me" logins on other browsers. The session or access token the request comes from can\'t be revoked here: log out instead.',
                responses: [
                    $this->schemas->empty(204, 'The session or access token was revoked'),
                    $this->schemas->message(404, 'The user has no such session or access token'),
                ],
                parameters: [
                    Parameter::make('session', 'path')
                        ->description('The "id" from GET /user/sessions: "session:{hash}" or "token:{id}".')
                        ->required(true)
                        ->setSchema(Schema::fromType(new StringType)),
                ],
                errorCodes: [ErrorCode::CurrentSession],
            ),
            'authentication.sessions.destroy-others' => new OperationSchema(
                summary: 'Revoke all other sessions',
                description: 'Ends every cookie session and revokes every access token of the user, except the one the request comes from. "Remember me" logins end too.',
                responses: [$this->schemas->empty(204, 'The other sessions and access tokens were revoked')],
            ),
            default => null,
        };
    }

    /**
     * A 200 response with a "data" list of the schema.
     */
    private function list(string $description, Type $item): Response
    {
        return Response::make(200)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType(
                $this->object(['data' => (new ArrayType)->setItems($item)]),
            ));
    }

    private function twoFactorChallenge(): OperationSchema
    {
        $tokenModeBody = (new ObjectType)
            ->addProperty('token', (new StringType)->setDescription('After a login: the access token for the Authorization: Bearer header.'))
            ->addProperty('expires_at', (new StringType)->format('date-time')->nullable(true)->setDescription('After a login: when the access token expires, or null when it does not.'))
            ->addProperty('device_token', (new StringType)->nullable(true)->setDescription('The device token for the '.$this->deviceHeader().' header, which skips the challenge at the next logins; null when the trust only lasts as long as the access token.'))
            ->setRequired(['device_token']);

        return new OperationSchema(
            summary: 'Pass the two-factor challenge',
            description: 'Send a code from the authenticator app ("code") or a recovery code ("recovery_code"). A challenge works once. A recovery code never trusts the device for longer ("remember_device").',
            requestBody: $this->object([
                'code' => new StringType,
                'recovery_code' => new StringType,
                'remember_device' => new BooleanType,
                'device_name' => (new StringType)->setMax(255),
            ], required: []),
            responses: [
                AuthenticationMode::mark(
                    Response::make(200)
                        ->setDescription('The challenge was passed')
                        ->setContent('application/json', Schema::fromType($tokenModeBody)),
                    Channel::Token,
                ),
                AuthenticationMode::mark($this->schemas->empty(204, 'The challenge was passed'), Channel::Session),
            ],
            parameters: [
                AuthenticationMode::mark(
                    Parameter::make($this->challengeHeader(), 'header')
                        ->description('After a login: the "challenge" from the login answer.')
                        ->setSchema(Schema::fromType(new StringType)),
                    Channel::Token,
                ),
            ],
            validatesInput: true,
            sessionDescription: 'After a login that answered "two_factor": true, send the session cookie. A logged-in user whose browser is not trusted (403 "two_factor_required") steps up with their session. "remember_device" trusts the browser for longer than the session, with the '.$this->deviceCookie().' cookie.',
            tokenDescription: 'After a login that answered "two_factor": true, send the "challenge" in the '.$this->challengeHeader().' header. A logged-in user whose access token is not trusted (403 "two_factor_required") steps up with that token. "remember_device" trusts the device for longer than the access token, with the "device_token" of the answer.',
        );
    }

    private function challengeHeader(): string
    {
        return config()->string('authentication.header_sessions.challenge_header', 'X-Auth-Challenge');
    }

    private function deviceCookie(): string
    {
        return config()->string('authentication.two_factor.device_trust.cookie', 'two_factor_device');
    }

    private function deviceHeader(): string
    {
        return config()->string('authentication.two_factor.device_trust.header', 'X-Two-Factor-Device');
    }

    private function login(): OperationSchema
    {
        $body = (new ObjectType)
            ->addProperty('two_factor', (new BooleanType)->setDescription('Whether a two-factor challenge must follow (POST /auth/two-factor-challenge).'))
            ->addProperty('challenge', AuthenticationMode::mark((new StringType)->setDescription('With "two_factor": true: the challenge id for the '.$this->challengeHeader().' header.'), Channel::Token))
            ->addProperty('token', AuthenticationMode::mark((new StringType)->setDescription('With "two_factor": false: the access token for the Authorization: Bearer header.'), Channel::Token))
            ->addProperty('expires_at', AuthenticationMode::mark((new StringType)->format('date-time')->nullable(true)->setDescription('When the access token expires, or null when it does not.'), Channel::Token))
            ->setRequired(['two_factor']);

        return new OperationSchema(
            summary: 'Log in with email and password or login code',
            description: '"password" is the user\'s password, or a login code from POST /auth/login-code for users that log in with codes. Users with two-factor authentication get "two_factor": true and pass the challenge next, unless the device is remembered.',
            requestBody: $this->object([
                Fortify::username() => $this->email(),
                'password' => new StringType,
                'remember' => AuthenticationMode::mark(new BooleanType, Channel::Session),
                'device_name' => AuthenticationMode::mark((new StringType)->setMax(255)->setDescription('The name of the access token; the user agent otherwise.'), Channel::Token),
            ], required: [Fortify::username(), 'password']),
            responses: [
                Response::make(200)
                    ->setDescription('Logged in, or the two-factor challenge must follow')
                    ->setContent('application/json', Schema::fromType($body)),
            ],
            parameters: [
                AuthenticationMode::mark(
                    Parameter::make($this->deviceHeader(), 'header')
                        ->description('The device token of a remembered device, which skips the two-factor challenge.')
                        ->setSchema(Schema::fromType(new StringType)),
                    Channel::Token,
                ),
            ],
            validatesInput: true,
            throttled: true,
            sessionDescription: 'For an SPA whose requests run through Sanctum\'s stateful stack: logs in to the cookie session. A remembered device comes along as the '.$this->deviceCookie().' cookie.',
            tokenDescription: 'Answers with an access token. A remembered device sends its device token in the '.$this->deviceHeader().' header.',
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
