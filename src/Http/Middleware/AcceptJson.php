<?php

declare(strict_types=1);

namespace Igne\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every package route a JSON route, so Fortify always takes its JSON
 * branches and errors render as JSON.
 */
final class AcceptJson
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
