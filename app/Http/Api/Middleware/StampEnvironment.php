<?php

namespace App\Http\Api\Middleware;

use App\Support\ApiEnvironment;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Labels every Partner API answer with the server that gave it (client
 * request 2026-11-23): header X-PayGate-Environment and, on JSON objects
 * (errors included), a top-level "environment" field: "live" or "sandbox".
 * A partner's logs then show which server each call reached.
 */
class StampEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $environment = ApiEnvironment::current();

        $response->headers->set(ApiEnvironment::HEADER, $environment);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data) && ($data === [] || ! array_is_list($data))) {
                $response->setData([...$data, 'environment' => $environment]);
            }
        }

        return $response;
    }
}
