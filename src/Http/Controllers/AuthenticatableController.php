<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Returns the authenticated user, through the resource configured in
 * "authentication.auth_resource".
 */
final class AuthenticatableController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var class-string<JsonResource> $resource */
        $resource = config('authentication.auth_resource');

        return (new $resource($request->user()))->response($request);
    }
}
