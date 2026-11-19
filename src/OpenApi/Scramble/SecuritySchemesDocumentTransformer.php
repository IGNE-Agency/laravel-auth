<?php

declare(strict_types=1);

namespace Igne\Auth\OpenApi\Scramble;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Igne\Auth\Http\Channel;
use Igne\Auth\OpenApi\Schemas\AuthenticationMode;

/**
 * Adds the two ways to authenticate: a bearer token, or the session cookie
 * plus the XSRF header (an SPA through Sanctum's stateful stack), each marked
 * with its authentication mode.
 */
final class SecuritySchemesDocumentTransformer implements DocumentTransformer
{
    public const string BEARER = 'bearer';

    public const string SESSION = 'session';

    public const string XSRF = 'xsrf';

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->components->addSecurityScheme(
            self::BEARER,
            AuthenticationMode::mark(SecurityScheme::http('bearer')->setDescription('An access token from POST /auth/login in token mode.'), Channel::Token),
        );

        $document->components->addSecurityScheme(
            self::SESSION,
            AuthenticationMode::mark(SecurityScheme::apiKey('cookie', $this->sessionCookie())->setDescription('The session cookie of an SPA that logged in in session mode.'), Channel::Session),
        );

        $document->components->addSecurityScheme(
            self::XSRF,
            AuthenticationMode::mark(SecurityScheme::apiKey('header', 'X-XSRF-TOKEN')->setDescription('The value of the XSRF-TOKEN cookie, required with the session cookie.'), Channel::Session),
        );
    }

    /**
     * Get the session cookie name: the pinned "authentication.openapi.session_cookie",
     * or else the app's session cookie.
     */
    private function sessionCookie(): string
    {
        $pinned = config('authentication.openapi.session_cookie');

        return is_string($pinned) && $pinned !== '' ? $pinned : config()->string('session.cookie');
    }
}
