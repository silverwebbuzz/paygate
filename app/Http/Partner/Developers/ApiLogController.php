<?php

namespace App\Http\Partner\Developers;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\ApiAuthenticator;
use App\Domain\PartnerApi\Models\ApiRequestLog;
use App\Domain\Reporting\Period;
use App\Http\Controller;
use App\Support\ApiEnvironment;
use App\Support\Hosts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner portal: API documentation and the log of the partner's own calls.
 */
class ApiLogController extends Controller
{
    public function docs(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($actor->can('api_keys.view') || $actor->can('api_logs.view'), 403);

        $partner = Partner::query()->findOrFail($actor->partner_id);

        return Inertia::render('partner/api-docs', [
            'base_url' => Hosts::url('api', '/v1'),
            'environment' => ApiEnvironment::current(),
            'key_id' => $partner->activeApiKey()->value('key_id'),
            'clock_skew' => ApiAuthenticator::MAX_CLOCK_SKEW,
            'rate_limit' => (int) config('paygate.api.rate_limit'),
            'overlap_hours' => IssueApiKey::OVERLAP_HOURS,
            'session_ttl' => $partner->session_ttl_minutes,
            'limits' => ['min' => $partner->deposit_min_amount, 'max' => $partner->deposit_max_amount],
        ]);
    }

    public function index(Request $request): Response
    {
        Gate::authorize('api_logs.view');

        /** @var User $actor */
        $actor = $request->user();
        $result = in_array($request->query('result'), ['ok', 'client', 'server'], true) ? (string) $request->query('result') : null;
        $search = trim((string) $request->query('search'));

        // Sent in the period (Today by default, client request 2026-11-18).
        $period = Period::fromRequest($request);

        $logs = ApiRequestLog::query()
            ->where('partner_id', $actor->partner_id)
            ->where(fn (Builder $query) => $period->apply($query, 'created_at'))
            ->when($result === 'ok', fn (Builder $query) => $query->where('status_code', '<', 400))
            ->when($result === 'client', fn (Builder $query) => $query->whereBetween('status_code', [400, 499]))
            ->when($result === 'server', fn (Builder $query) => $query->where('status_code', '>=', 500))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('partner_transaction_id', $search)
                ->orWhere('request_id', $search)
                ->orWhere('key_id_sent', $search)
                ->orWhere('error_code', $search)
                ->orWhereLike('path', "%{$search}%")))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ApiRequestLog $log) => [
                'id' => $log->id,
                'method' => $log->method,
                'path' => $log->path,
                'status_code' => $log->status_code,
                'error_code' => $log->error_code,
                'host' => $log->host,
                'key_id' => $log->key_id_sent,
                'duration_ms' => $log->duration_ms,
                'ip' => $log->ip,
                'request_id' => $log->request_id,
                'order_id' => $log->partner_transaction_id,
                'at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('partner/api-logs', [
            'logs' => $logs,
            // Each server keeps its own log (client request 2026-11-23).
            'server' => ['environment' => ApiEnvironment::current(), 'base_url' => Hosts::url('api', '/v1')],
            'filters' => ['result' => $result, 'search' => $search],
            'period' => $period->toArray(),
        ]);
    }
}
