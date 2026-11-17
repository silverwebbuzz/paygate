<?php

namespace App\Http\Admin\Security;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Settings;
use App\Domain\SecurityReport\Catalogue;
use App\Domain\SecurityReport\Jobs\RunSecurityScan;
use App\Domain\SecurityReport\Models\SecurityReportEntry;
use App\Domain\SecurityReport\Models\SecurityScan;
use App\Domain\SecurityReport\SecurityReport;
use App\Domain\SecurityReport\SecurityScanner;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as FileResponse;

/**
 * Admin › Testing › Security Report (super admins only, every environment):
 * run the automated scan, mark the manual tests, findings and client
 * decisions, fill in the report details, preview the report and download it
 * as PDF for the client (SecurityReport; Document/Security-Testing.md).
 */
class SecurityReportController extends Controller
{
    public function index(Request $request, SecurityReport $report): Response
    {
        $this->authorizeSuperAdmin($request);

        $scans = SecurityScan::query()->with('requester:id,name')->latest('requested_at')->limit(10)->get();
        $latest = $report->latestScan();

        return Inertia::render('admin/security-report', [
            'details' => $report->details(),
            'target' => SecurityScanner::target(),
            'load' => ['requests' => (int) config('paygate.security_scan.load_requests'), 'concurrency' => (int) config('paygate.security_scan.load_concurrency')],
            'scans' => $scans->map(fn (SecurityScan $scan) => [
                'id' => $scan->id,
                'status' => $scan->shownStatus(),
                'passed' => $scan->passed,
                'failed' => $scan->failed,
                'warnings' => $scan->warnings,
                'error' => $scan->error,
                'requested_by' => $scan->requester->name,
                'requested_at' => $scan->requested_at->toIso8601String(),
                'finished_at' => $scan->finished_at?->toIso8601String(),
            ]),
            'running' => $scans->contains(fn (SecurityScan $scan) => $scan->isActive()),
            'latest' => $latest === null ? null : [
                'finished_at' => $latest->finished_at?->toIso8601String(),
                'results' => $latest->results ?? [],
                'performance' => $latest->performance ?? [],
            ],
            'findings' => Catalogue::findings(),
            'decisions' => Catalogue::decisions(),
            'groups' => Catalogue::manualTests(),
            'entries' => $report->entries()->map(fn (SecurityReportEntry $entry) => [
                'status' => $entry->status,
                'note' => $entry->note,
                'updated_by' => $entry->editor?->name,
                'updated_at' => $entry->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function scan(Request $request): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);

        $active = SecurityScan::query()->whereIn('status', ['queued', 'running'])->get()->contains(fn (SecurityScan $scan) => $scan->isActive());

        if ($active) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A scan is already running. Wait for it to finish.')]);

            return back();
        }

        $scan = SecurityScan::query()->create([
            'status' => 'queued',
            'target' => SecurityScanner::target(),
            'requested_by' => $actor->id,
            'requested_at' => now(),
        ]);

        RunSecurityScan::dispatch($scan->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scan started. It takes a minute or two; this page updates by itself.')]);

        return back();
    }

    public function mark(Request $request, string $key): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);
        $allowed = Catalogue::keys()[$key] ?? null;
        abort_if($allowed === null, 404);

        $data = $request->validate([
            'status' => ['nullable', Rule::in($allowed)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        SecurityReportEntry::query()->updateOrCreate(['entry_key' => $key], [
            'status' => $data['status'] ?? null,
            'note' => isset($data['note']) && trim($data['note']) !== '' ? trim($data['note']) : null,
            'updated_by' => $actor->id,
        ]);

        return back();
    }

    public function details(Request $request, Settings $settings): RedirectResponse
    {
        $actor = $this->authorizeSuperAdmin($request);

        $data = $request->validate([
            'client' => ['nullable', 'string', 'max:120'],
            'round' => ['nullable', 'string', 'max:20'],
            'environment' => ['nullable', 'string', 'max:120'],
            'prepared_by' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings->set(Settings::SECURITY_REPORT, array_map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null, $data), $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report details saved.')]);

        return back();
    }

    public function preview(Request $request, SecurityReport $report): HttpResponse
    {
        $this->authorizeSuperAdmin($request);

        return response($report->html())->header('Cache-Control', 'private, no-store');
    }

    public function download(Request $request, SecurityReport $report): FileResponse
    {
        $this->authorizeSuperAdmin($request);

        return $report->download();
    }

    private function authorizeSuperAdmin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403);

        return $user;
    }
}
