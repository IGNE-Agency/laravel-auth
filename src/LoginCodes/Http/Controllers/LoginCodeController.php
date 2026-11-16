<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Http\Controllers;

use Igne\Auth\Authentication\UserProviderResolver;
use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\LoginCodes\Actions\CreateLoginCodeAction;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\Http\Requests\RequestLoginCodeRequest;
use Igne\Auth\LoginCodes\Http\Responses\LoginCodeRequestedResponse;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Timebox;

/**
 * Emails a login code to a user that can log in with one. The answer is the
 * same for every valid request, and the work takes a fixed minimum time, so
 * neither reveals whether the account exists.
 */
final class LoginCodeController
{
    public function __construct(
        private readonly UserProviderResolver $users,
        private readonly Repository $config,
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly Timebox $timebox,
    ) {}

    public function store(RequestLoginCodeRequest $request): JsonResponse
    {
        $this->timebox->call(function () use ($request): void {
            $user = $this->users->findByUsername($request->username());

            if (! $user instanceof LoginCodeAuthenticatable || ! $user->canAuthenticateWithLoginCode()) {
                return;
            }

            /** @var class-string<CreateLoginCodeAction> $action */
            $action = $this->config->get('authentication.login_codes.actions.create_login_code');

            $this->container->make($action)->execute($user, LoginCodePurpose::Login, $request);

            $this->events->dispatch(new LoginCodeRequested($user, LoginCodePurpose::Login));
        }, (int) $this->config->get('auth.timebox_duration', 200000));

        return (new LoginCodeRequestedResponse)->toResponse($request);
    }
}
