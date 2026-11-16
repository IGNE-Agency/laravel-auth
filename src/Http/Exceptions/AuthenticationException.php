<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Exceptions;

use Igne\Auth\Http\ErrorCode;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The base of the package's exceptions. It renders as {message, code}.
 */
abstract class AuthenticationException extends RuntimeException implements Responsable, ShouldntReport
{
    /**
     * Get the stable error code of the exception.
     */
    abstract public function errorCode(): ErrorCode;

    /**
     * Get the HTTP status of the response.
     */
    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }

    /**
     * Render the exception as a JSON response.
     *
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'code' => $this->errorCode()->value,
        ], $this->status());
    }
}
