<?php

namespace App\Http\Shared\Reports;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\Reporting\Actions\ManageReportExports;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportCatalog;
use App\Domain\Reporting\Reports\Report;
use App\Domain\Reporting\Scope;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports (all portals): the catalogue, a preview of the chosen report
 * (first 200 rows and the totals) and CSV / Excel exports prepared in the
 * background, listed with their status.
 */
class ReportController extends Controller
{
    private const PREVIEW_ROWS = 200;

    public function index(Request $request, ReportCatalog $catalog): Response
    {
        Gate::authorize('reports.view');

        $actor = $this->actor($request);
        $reports = $catalog->for($actor->type);
        $key = is_string($request->query('report')) && isset($reports[$request->query('report')]) ? (string) $request->query('report') : (string) array_key_first($reports);
        $report = $reports[$key];
        $scope = Scope::of($actor);
        $period = $this->period($request);
        $filters = $this->filters($request, $report, $scope);

        // Only the columns this viewer may see ever leave the server.
        $visible = array_flip(array_column($report->columns($scope), 'key'));
        $rows = [];

        foreach ($report->rows($scope, $period, $filters) as $row) {
            if (count($rows) === self::PREVIEW_ROWS) {
                break;
            }

            $rows[] = array_intersect_key($row, $visible);
        }

        return Inertia::render('reports/index', [
            'portal' => $actor->type->value,
            'catalog' => array_values(array_map(fn (Report $item) => ['key' => $item->key(), 'title' => $item->title(), 'description' => $item->description()], $reports)),
            'report' => [
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
                'uses_period' => $report->usesPeriod(),
                'columns' => $report->columns($scope),
                'filters' => $report->filters($scope),
            ],
            'period' => $period->toArray(),
            'filters' => $filters,
            'rows' => $rows,
            'preview_limit' => self::PREVIEW_ROWS,
            'totals' => array_intersect_key($report->totals($scope, $period, $filters), $visible + ['_count' => true]),
            'options' => [
                'partner' => match ($actor->type) {
                    UserType::Admin => Partner::query()->orderBy('code')->get(['id', 'code', 'name']),
                    UserType::Branch => Partner::query()->whereIn('id', PartnerBranchMapping::query()->where('branch_id', $actor->branch_id)->select('partner_id'))->orderBy('code')->get(['id', 'code', 'name']),
                    UserType::Partner => [],
                },
                'branch' => $actor->isType(UserType::Admin) ? Branch::query()->orderBy('code')->get(['id', 'code', 'name']) : [],
            ],
            'exports' => ReportExport::query()->where('user_id', $actor->id)->latest('created_at')->limit(10)->get()->map(fn (ReportExport $export) => [
                'id' => $export->id,
                'report' => isset($reports[$export->report]) ? $reports[$export->report]->title() : $export->report,
                'format' => $export->format,
                'status' => $export->status,
                'rows' => $export->rows,
                'file_id' => $export->file_id,
                'error' => $export->error,
                'created_at' => $export->created_at->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
            ]),
            'can' => ['export' => $actor->can('reports.export')],
        ]);
    }

    public function export(Request $request, ReportCatalog $catalog, ManageReportExports $exports): RedirectResponse
    {
        Gate::authorize('reports.export');

        $actor = $this->actor($request);
        $data = $request->validate([
            'report' => ['required', 'string', Rule::in(array_keys($catalog->for($actor->type)))],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ]);

        $report = $catalog->find($actor->type, $data['report']);
        abort_if($report === null, 404);

        $exports->request($actor, $report, $this->period($request), $this->filters($request, $report, Scope::of($actor)), $data['format']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':report export started. It appears under Your exports when ready.', ['report' => $report->title()])]);

        return back();
    }

    private function period(Request $request): Period
    {
        $input = fn (string $key) => is_string($request->input($key)) ? (string) $request->input($key) : null;

        return Period::resolve($input('range') ?? 'this_month', $input('from'), $input('to'));
    }

    /**
     * Only the filters this report offers this viewer.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request, Report $report, Scope $scope): array
    {
        $filters = [];

        foreach (array_keys($report->filters($scope)) as $key) {
            $value = $request->input($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $filters;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
