<?php

namespace App\Domain\SecurityReport;

use App\Domain\Platform\Settings;
use App\Domain\SecurityReport\Models\SecurityReportEntry;
use App\Domain\SecurityReport\Models\SecurityScan;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security report for the client: the fixed content (Catalogue), the
 * super admin's statuses and notes (security_report_entries), the latest
 * finished scan and the report details (Settings). Shown as HTML on the page
 * (preview) and downloaded as PDF — the same document.
 */
class SecurityReport
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array{client: ?string, round: ?string, environment: ?string, prepared_by: ?string, note: ?string}
     */
    public function details(): array
    {
        /** @var array{client: ?string, round: ?string, environment: ?string, prepared_by: ?string, note: ?string} */
        return [
            'client' => null, 'round' => '1', 'environment' => null, 'prepared_by' => null, 'note' => null,
            ...(array) $this->settings->get(Settings::SECURITY_REPORT),
        ];
    }

    public function latestScan(): ?SecurityScan
    {
        return SecurityScan::query()->where('status', 'done')->latest('finished_at')->first();
    }

    /**
     * @return Collection<string, SecurityReportEntry>
     */
    public function entries(): Collection
    {
        return SecurityReportEntry::query()->with('editor:id,name')->get()->keyBy('entry_key');
    }

    /**
     * Everything the report shows.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $entries = $this->entries();
        $scan = $this->latestScan();
        $status = fn (string $key, ?string $default = null) => $entries->get($key)->status ?? $default;
        $note = fn (string $key) => $entries->get($key)?->note;

        $findings = array_map(fn (array $finding) => [
            ...$finding,
            'status' => $status($finding['key'], $finding['default']),
            'note' => $note($finding['key']),
        ], Catalogue::findings());

        $decisions = array_map(fn (array $decision) => [
            ...$decision,
            'status' => $status($decision['key'], 'pending'),
            'note' => $note($decision['key']),
        ], Catalogue::decisions());

        $groups = array_map(fn (array $group) => [
            ...$group,
            'tests' => array_map(fn (array $test) => [...$test, 'status' => $status($test['key']), 'note' => $note($test['key'])], $group['tests']),
        ], Catalogue::manualTests());

        $tests = array_merge(...array_map(fn (array $group) => $group['tests'], $groups));
        $count = fn (string $value) => count(array_filter($tests, fn (array $test) => $test['status'] === $value));
        $open = array_filter($findings, fn (array $finding) => in_array($finding['status'], ['open', 'fixed'], true));
        $bySeverity = [];

        foreach (Catalogue::SEVERITIES as $severity) {
            $bySeverity[$severity] = count(array_filter($findings, fn (array $finding) => $finding['severity'] === $severity));
        }

        return [
            'details' => $this->details(),
            'generated_at' => now(),
            'target' => $scan->target ?? SecurityScanner::target(),
            'scan' => $scan === null ? null : [
                'finished_at' => $scan->finished_at,
                'passed' => $scan->passed,
                'failed' => $scan->failed,
                'warnings' => $scan->warnings,
                'results' => $scan->results ?? [],
                'performance' => $scan->performance ?? [],
            ],
            'verified' => Catalogue::verified(),
            'findings' => $findings,
            'by_severity' => $bySeverity,
            'open_findings' => count($open),
            'decisions' => $decisions,
            'groups' => $groups,
            'tests' => ['total' => count($tests), 'pass' => $count('pass'), 'fail' => $count('fail'), 'na' => $count('na'), 'untested' => count($tests) - $count('pass') - $count('fail') - $count('na')],
        ];
    }

    public function html(): string
    {
        return view('security.report', $this->data())->render();
    }

    public function download(): Response
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->addInfo('Title', 'PayGate security & performance report');
        $dompdf->addInfo('Author', 'PayGate');

        $round = preg_replace('/[^A-Za-z0-9-]/', '', (string) $this->details()['round']) ?: '1';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="paygate-security-report-round-'.$round.'-'.now()->format('Y-m-d').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
