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
 *
 * Also the host called, the key id sent and the error code (client request
 * 2026-11-23), so a call to the wrong server can be traced. A refused call
 * whose key id is a partner's here is shown in that partner's API Logs.
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
        $keyIdSent = $request->header('X-Key-Id');
        $keyIdSent = is_string($keyIdSent) && $keyIdSent !== '' ? mb_substr($keyIdSent, 0, 64) : null;

        if (! $key instanceof PartnerApiKey && $keyIdSent !== null) {
            $key = PartnerApiKey::query()->where('key_id', $keyIdSent)->first();
        }
        $started = (float) $request->attributes->get('api_started_at', microtime(true));
        $orderId = $request->input('order_id');

        try {
            ApiRequestLog::create([
                'partner_id' => $partner instanceof Partner ? $partner->id : ($key instanceof PartnerApiKey ? $key->partner_id : null),
                'api_key_id' => $key instanceof PartnerApiKey ? $key->id : null,
                'key_id_sent' => $keyIdSent,
                'host' => mb_substr($request->getHost(), 0, 255),
                'error_code' => $this->errorCode($response),
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

    private function errorCode(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $code = json_decode((string) $response->getContent(), true)['error']['code'] ?? null;

        return is_string($code) ? mb_substr($code, 0, 50) : null;
    }
}
