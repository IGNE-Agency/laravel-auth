<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions\Http\Controllers;

use Igne\Auth\Http\Exceptions\CurrentSessionException;
use Igne\Auth\Sessions\ActiveSessions;
use Igne\Auth\Sessions\Http\Resources\ActiveSessionResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use LogicException;

/**
 * Lists and revokes the authenticated user's web sessions and access tokens.
 * The session or access token the request comes from stays: logging out ends
 * it.
 */
final class ActiveSessionController
{
    public function index(Request $request, ActiveSessions $sessions): AnonymousResourceCollection
    {
        return ActiveSessionResource::collection($sessions->of($request, $this->user($request)));
    }

    /**
     * @throws CurrentSessionException
     */
    public function destroy(Request $request, ActiveSessions $sessions, string $session): Response
    {
        abort_unless(
            $sessions->revoke($request, $this->user($request), $session),
            Response::HTTP_NOT_FOUND,
            Response::$statusTexts[Response::HTTP_NOT_FOUND],
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    public function destroyOthers(Request $request, ActiveSessions $sessions): Response
    {
        $sessions->revokeOthers($request, $this->user($request));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function user(Request $request): Authenticatable
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            throw new LogicException('The session routes need an authenticated user.');
        }

        return $user;
    }
}
