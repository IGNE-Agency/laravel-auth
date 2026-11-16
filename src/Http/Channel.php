<?php

declare(strict_types=1);

namespace Igne\Auth\Http;

use Illuminate\Http\Request;

/**
 * How a client is authenticated. A request is in session mode when Sanctum's
 * stateful stack ran (an SPA with a session cookie); otherwise it is in token
 * mode (a bearer-token client). The middleware stack decides, never a header
 * the client sends.
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
        $channel = $request->attributes->get('sanctum') === true ? self::Session : self::Token;

        $request->attributes->set(self::ATTRIBUTE, $channel);

        return $channel;
    }
}
