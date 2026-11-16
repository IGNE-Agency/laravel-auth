<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Returns the authenticated user, through the resource configured in
 * "authentication.auth_resource".
 */
final class AuthenticatableController
{
    public function __invoke(Request $request): JsonResponse
    {
        $resource = config()->string('authentication.auth_resource');

        if (! is_a($resource, JsonResource::class, true)) {
            throw new LogicException("The authenticatable resource [{$resource}] must be a JSON resource.");
        }

        return (new $resource($request->user()))->response($request);
    }
}
