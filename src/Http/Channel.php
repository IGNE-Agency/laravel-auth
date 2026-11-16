<?php

declare(strict_types=1);

namespace Igne\Auth\Http;

use Igne\Auth\Sessions\HeaderSessionFactory;
use Illuminate\Http\Request;

/**
 * How a client is authenticated. A request is in session mode when Sanctum's
 * stateful stack ran (an SPA with a session cookie) or when the route runs in
 * a cookie session of its own (the "web" group, such as Nova); otherwise it is
 * in token mode (a bearer-token client, whose session is a header session).
 * The middleware stack decides, never a header the client sends.
 */
enum Channel: string
{
    case Session = 'session';
    case Token = 'token';

    /**
     * The request attribute that holds the channel.
     */
    public const string ATTRIBUTE = 'authentication.channel';

    /**
     * Get the channel of the given request and store it on the request.
     */
    public static function of(Request $request): self
    {
        $channel = match (true) {
            $request->attributes->get('sanctum') === true => self::Session,
            $request->hasSession() && ! HeaderSessionFactory::isHeaderSession($request->session()) => self::Session,
            default => self::Token,
        };

        $request->attributes->set(self::ATTRIBUTE, $channel);

        return $channel;
    }
}
