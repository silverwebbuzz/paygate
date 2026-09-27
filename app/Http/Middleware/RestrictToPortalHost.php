<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Package routes (Fortify, passkeys, ...) are registered without a domain, so
 * they would otherwise answer on every host. Only the portal host may serve them;
 * the payer and API hosts only serve routes explicitly registered for them.
 */
class RestrictToPortalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route !== null && $route->getDomain() === null && $request->getHost() !== config('app.domains.app')) {
            abort(404);
        }

        return $next($request);
    }
}
