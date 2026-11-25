<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Http\Controllers;

use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Authentication\ConfirmIdentity;
use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\Http\Exceptions\LoginCodeUnavailableException;
use Igne\Auth\LoginCodes\Actions\CreateLoginCodeAction;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emails a confirmation code to the authenticated user, when an emailed code
 * is how they confirm their identity. They send it as the "password" to the
 * confirm-password route.
 */
final class ConfirmationCodeController
{
    public function __construct(
        private readonly ConfirmIdentity $confirmIdentity,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Request a confirmation code.
     *
     * Emails a one-time code to a user whose "confirmation_method" is "login_code". Confirm by sending it as
     * "password" to POST /user/confirm-password.
     *
     * @response \Illuminate\Http\JsonResponse<array{message: string}, 202>
     *
     * @throws LoginCodeUnavailableException
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof LoginCodeAuthenticatable || $this->confirmIdentity->methodFor($user) !== AuthenticationMethod::LoginCode) {
            throw new LoginCodeUnavailableException;
        }

        $actionClass = $this->config->string('authentication.login_codes.actions.create_login_code');
        $action = $this->container->make($actionClass);

        if (! $action instanceof CreateLoginCodeAction) {
            throw new LogicException("The create login code action [{$actionClass}] must extend [".CreateLoginCodeAction::class.'].');
        }

        $action->execute($user, LoginCodePurpose::Confirm, $request);

        $this->events->dispatch(new LoginCodeRequested($user, LoginCodePurpose::Confirm));

        return new JsonResponse(
            ['message' => __('authentication::messages.confirmation_code_sent')],
            Response::HTTP_ACCEPTED,
        );
    }
}
