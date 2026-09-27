<?php

namespace App\Http\Api\Middleware;

use App\Domain\PartnerApi\ApiAuthenticator;
use App\Domain\PartnerApi\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-request authentication for the Partner API (ApiAuthenticator), then
 * a per-partner rate limit. The partner and key are put on the request for
 * the controllers and the request log.
 */
class AuthenticatePartner
{
    public function __construct(private ApiAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        ['partner' => $partner, 'key' => $key] = $this->authenticator->authenticate(
            $request->header('X-Key-Id'),
            $request->header('X-Timestamp'),
            $request->header('X-Nonce'),
            $request->header('X-Signature'),
            $request->getMethod(),
            $request->getRequestUri(),
            $request->getContent(),
            $request->ip(),
        );

        $request->attributes->set('partner', $partner);
        $request->attributes->set('api_key', $key);

        $limit = (int) config('paygate.api.rate_limit');

        if (! RateLimiter::attempt('partner-api:'.$partner->id, $limit, fn () => true, 60)) {
            throw new ApiException('rate_limited', "Too many requests: at most {$limit} per minute. Retry in ".RateLimiter::availableIn('partner-api:'.$partner->id).' seconds.', 429);
        }

        return $next($request);
    }
}
