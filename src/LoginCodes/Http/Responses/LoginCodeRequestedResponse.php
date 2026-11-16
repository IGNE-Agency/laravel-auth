<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one answer to a login code request: whether or not the account exists,
 * can log in with a code or asked too often, so the response reveals none of
 * it.
 */
final class LoginCodeRequestedResponse implements Responsable
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(
            ['message' => __('authentication::messages.login_code_sent')],
            Response::HTTP_ACCEPTED,
        );
    }
}
