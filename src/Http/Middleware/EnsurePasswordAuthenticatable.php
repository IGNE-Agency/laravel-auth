<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Middleware;

use Closure;
use Igne\Auth\Authentication\Contracts\PasswordAuthenticatable;
use Igne\Auth\Http\Exceptions\PasswordUnavailableException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only lets users through that can log in with a password.
 */
final class EnsurePasswordAuthenticatable
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws PasswordUnavailableException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof PasswordAuthenticatable || ! $user->canAuthenticateWithPassword()) {
            throw new PasswordUnavailableException;
        }

        return $next($request);
    }
}
