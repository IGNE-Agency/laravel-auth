<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes;

use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Illuminate\Contracts\Config\Repository;
use Laravel\Fortify\Fortify;
use SensitiveParameter;

/**
 * Builds the link a login code mail offers next to the code, from
 * "authentication.login_codes.link_url". The frontend page it opens posts the
 * code to the login route; following the link never logs in by itself.
 */
final class LoginCodeUrl
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Get the link for the user's code with the "{email}" and "{code}"
     * placeholders filled in, or null when no link URL is configured.
     */
    public function for(LoginCodeAuthenticatable $user, #[SensitiveParameter] string $code): ?string
    {
        $url = $this->config->get('authentication.login_codes.link_url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $email = data_get($user, Fortify::username());

        return strtr($url, [
            '{email}' => rawurlencode(is_scalar($email) ? (string) $email : ''),
            '{code}' => rawurlencode($code),
        ]);
    }
}
