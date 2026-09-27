<?php

namespace App\Http\Api\Middleware;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PartnerApi\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one api_request_logs row per Partner API call, after the response
 * has been sent (no request or response bodies: they hold customer data).
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $partner = $request->attributes->get('partner');
        $key = $request->attributes->get('api_key');
        $started = (float) $request->attributes->get('api_started_at', microtime(true));
        $orderId = $request->input('order_id');

        try {
            ApiRequestLog::create([
                'partner_id' => $partner instanceof Partner ? $partner->id : null,
                'api_key_id' => $key instanceof PartnerApiKey ? $key->id : null,
                'method' => $request->getMethod(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'ip' => $request->ip(),
                'request_id' => Context::get('request_id'),
                'partner_transaction_id' => is_string($orderId) ? mb_substr($orderId, 0, 100) : null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
