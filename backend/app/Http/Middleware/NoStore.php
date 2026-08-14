<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStore
{
    /**
     * No API response is cacheable: each one depends either on the current
     * instant (a link expires) or on the bearer of the token (an inventory is
     * personal). Symfony defaults to "no-cache, private", which still lets an
     * intermediary store the response as long as it revalidates — not enough
     * for a body carrying a JWT, and not enough for a shared file, which a
     * cached copy would serve without re-checking expiry or password.
     *
     * Prepended to the api group so that responses built from an exception
     * (422, 429) travel back through it too.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
