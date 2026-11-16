<?php

declare(strict_types=1);

namespace Igne\Auth\Sessions\Http\Middleware;

use Closure;
use Igne\Auth\Http\Channel;
use Igne\Auth\Sessions\HeaderSessionFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives a token-mode request its own server-side session, identified by a
 * header instead of a cookie. Fortify's challenge and password confirmation
 * flows then run unchanged for bearer-token clients. The session never sets a
 * cookie, and it is not the session store the "web" guard uses, so a login on
 * that guard can't leak into a bearer request.
 *
 * Session-mode requests already have a session and pass through unchanged.
 */
final class StartHeaderSession
{
    public function __construct(private readonly HeaderSessionFactory $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() || Channel::of($request) === Channel::Session) {
            return $next($request);
        }

        $session = $this->sessions->forRequest($request);
        $session->start();

        $incomingId = $session->getId();

        $request->setLaravelSession($session);

        // A token client can't hold a remember cookie.
        if ($request->exists('remember')) {
            $request->merge(['remember' => false]);
        }

        $response = $next($request);

        $this->sessions->persist($session, $incomingId);

        return $response;
    }
}
