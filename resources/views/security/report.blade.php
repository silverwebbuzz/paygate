@php
    $label = [
        'pass' => 'Pass', 'fail' => 'Fail', 'warn' => 'Warning', 'na' => 'N/A',
        'open' => 'Open', 'fixed' => 'Fixed, awaiting deployment', 'verified' => 'Fixed & verified', 'accepted' => 'Risk accepted',
        'pending' => 'Awaiting decision', 'agreed' => 'Agreed', 'declined' => 'Declined',
        'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info',
    ];
    $tone = [
        'pass' => 'ok', 'verified' => 'ok', 'agreed' => 'ok',
        'fail' => 'er', 'open' => 'er', 'critical' => 'er', 'high' => 'er', 'declined' => 'er',
        'warn' => 'wn', 'fixed' => 'wn', 'pending' => 'wn', 'medium' => 'wn',
        'low' => 'in', 'info' => 'nt', 'na' => 'nt', 'accepted' => 'nt',
    ];
    $badge = fn (?string $value) => $value === null
        ? '<span class="b nt">Not tested</span>'
        : '<span class="b '.($tone[$value] ?? 'nt').'">'.e($label[$value] ?? ucfirst($value)).'</span>';
    $highOpen = collect($findings)->whereIn('severity', ['critical', 'high'])->whereIn('status', ['open', 'fixed'])->count();
    $date = fn ($at) => $at?->timezone(config('app.business_timezone'))->format('j M Y, H:i');
    $areas = collect($scan['results'] ?? [])->groupBy('area');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PayGate security &amp; performance report</title>
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.6pt; color: #0f172a; line-height: 1.4; margin: 0; background: #fff; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; border-top: 1px solid #e4e7ec; padding-top: 3px; font-size: 7.5pt; color: #64748b; }
        .pagenum:before { content: counter(page); }
        .banner { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
        .banner td { background: #3e1264; color: #fff; padding: 14px 16px 12px; }
        .eyebrow { font-size: 8pt; color: #f9a8d4; font-weight: bold; letter-spacing: 1px; }
        h1 { font-size: 17pt; line-height: 1.15; margin: 3px 0; font-weight: normal; color: #fff; }
        .banner p { margin: 0; font-size: 8pt; color: #fce7f3; }
        h2 { font-size: 12pt; color: #3e1264; margin: 14px 0 5px; padding-bottom: 2px; border-bottom: 2px solid #d6287f; page-break-after: avoid; }
        h3 { font-size: 9.5pt; color: #3e1264; margin: 9px 0 3px; page-break-after: avoid; }
        p { margin: 0 0 5px; }
        .small { font-size: 7.6pt; color: #475569; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; text-align: left; }
        .grid th { background: #f8fafc; color: #475569; font-size: 7.6pt; font-weight: bold; border: 1px solid #e4e7ec; padding: 4px 6px; }
        .grid td { border: 1px solid #e4e7ec; padding: 4px 6px; }
        .grid tr { page-break-inside: avoid; }
        .facts td { border: 1px solid #e4e7ec; padding: 4px 7px; }
        .facts td.k { width: 24%; background: #f8fafc; color: #475569; font-size: 7.8pt; font-weight: bold; }
        .kpi td { width: 25%; border: 1px solid #e4e7ec; padding: 7px 8px; text-align: center; }
        .kpi .n { font-size: 15pt; color: #3e1264; }
        .kpi .l { font-size: 7.4pt; color: #64748b; }
        .box { padding: 7px 9px; margin: 6px 0; page-break-inside: avoid; }
        .good { background: #ecfdf5; border: 1px solid #a7f3d0; }
        .warnbox { background: #fffbeb; border: 1px solid #fcd34d; }
        .bad { background: #fef2f2; border: 1px solid #fecaca; }
        .note { background: #eff6ff; border: 1px solid #bfdbfe; }
        .b { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7.2pt; font-weight: bold; white-space: nowrap; }
        .ok { background: #dcfce7; color: #166534; }
        .er { background: #fee2e2; color: #991b1b; }
        .wn { background: #fef3c7; color: #92400e; }
        .in { background: #dbeafe; color: #1e40af; }
        .nt { background: #f1f5f9; color: #475569; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7.4pt; word-wrap: break-word; }
        .w-id { width: 7%; } .w-st { width: 15%; } .w-sev { width: 9%; }
        .break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="footer">PayGate · Security &amp; performance assessment · Round {{ $details['round'] ?? '1' }} · Confidential · page <span class="pagenum"></span></div>

    <table class="banner">
        <tr>
            <td bgcolor="#3e1264">
                <div class="eyebrow">PAYGATE</div>
                <h1>Security &amp; Performance Assessment</h1>
                <p>
                    @if ($details['client']) Prepared for {{ $details['client'] }} · @endif
                    Round {{ $details['round'] ?? '1' }}
                    · {{ $details['environment'] ?: $target['portal'] }}
                    · {{ $date($generated_at) }}
                </p>
            </td>
        </tr>
    </table>

    <h2>1. Summary</h2>
    <table class="kpi">
        <tr>
            <td><div class="n">{{ $by_severity['critical'] + $by_severity['high'] }}</div><div class="l">Critical / high findings</div></td>
            <td><div class="n">{{ $open_findings }}</div><div class="l">Findings not yet verified on the live system</div></td>
            <td><div class="n">{{ $scan ? $scan['passed'].' / '.($scan['passed'] + $scan['failed'] + $scan['warnings']) : '—' }}</div><div class="l">Automated checks passed</div></td>
            <td><div class="n">{{ $tests['pass'] }} / {{ $tests['total'] }}</div><div class="l">Manual tests passed</div></td>
        </tr>
    </table>

    @if ($highOpen === 0 && $by_severity['critical'] + $by_severity['high'] === 0)
        <div class="box good"><strong>No critical or high-severity issues were found.</strong> The core protections are in place: signed partner API requests, separation of each partner’s and branch’s data, protection against double approvals, private payment proofs and a tamper-proof audit trail.</div>
    @else
        <div class="box bad"><strong>{{ $highOpen }} critical or high-severity finding(s) are still open.</strong> See section 3.</div>
    @endif

    @if ($scan && $scan['failed'] > 0)
        <div class="box warnbox"><strong>The latest automated scan shows {{ $scan['failed'] }} failed {{ \Illuminate\Support\Str::plural('check', $scan['failed']) }}.</strong> They are listed in section 5; the findings in section 3 explain the cause and the fix.</div>
    @endif

    @if ($details['note'])
        <p>{!! nl2br(e($details['note'])) !!}</p>
    @endif

    <p>
        Assessment methods: (1) a review of the application source code; (2) an automated scan the server runs against its own public addresses
        @if ($scan) (last run {{ $date($scan['finished_at']) }}: {{ $scan['passed'] }} passed, {{ $scan['failed'] }} failed, {{ $scan['warnings'] }} {{ \Illuminate\Support\Str::plural('warning', $scan['warnings']) }})@endif;
        (3) manual tests logged in with one user per role ({{ $tests['pass'] }} passed, {{ $tests['fail'] }} failed, {{ $tests['untested'] }} not yet tested).
    </p>

    <h2>2. Scope</h2>
    <table class="facts">
        <tr><td class="k">Portals</td><td class="mono">{{ $target['portal'] }}</td></tr>
        <tr><td class="k">Partner API</td><td class="mono">{{ $target['api'] }}</td></tr>
        <tr><td class="k">Payment page</td><td class="mono">{{ $target['pay'] }}</td></tr>
        <tr><td class="k">Code review</td><td>The complete application source code</td></tr>
        <tr><td class="k">Automated scan</td><td>Read-only, without login, from the server itself; a light load of {{ config('paygate.security_scan.load_requests') }} requests per page, {{ config('paygate.security_scan.load_concurrency') }} at a time</td></tr>
        <tr><td class="k">Not included</td><td>Denial-of-service or heavy load on the live system, social engineering, the hosting provider’s infrastructure</td></tr>
        @if ($details['prepared_by'])<tr><td class="k">Tested by</td><td>{{ $details['prepared_by'] }}</td></tr>@endif
    </table>

    <h2 class="break">3. Findings</h2>
    <table class="grid">
        <tr><th class="w-id">ID</th><th style="width: 40%">Finding</th><th class="w-sev">Severity</th><th>Fix</th><th style="width: 13%">Status</th></tr>
        @foreach ($findings as $finding)
            <tr>
                <td>{{ $finding['id'] }}</td>
                <td><strong>{{ $finding['title'] }}.</strong> {{ $finding['detail'] }}</td>
                <td>{!! $badge($finding['severity']) !!}</td>
                <td>{{ $finding['fix'] }}@if ($finding['note'])<br><span class="small">{{ $finding['note'] }}</span>@endif</td>
                <td>{!! $badge($finding['status']) !!}</td>
            </tr>
        @endforeach
    </table>

    <h2>4. Verified as secure (code review)</h2>
    <table class="grid">
        @foreach ($verified as $row)
            <tr><td style="width: 18%"><strong>{{ $row['area'] }}</strong></td><td>{{ $row['result'] }}</td><td style="width: 7%">{!! $badge('pass') !!}</td></tr>
        @endforeach
    </table>

    <h2 class="break">5. Automated scan</h2>
    @if (! $scan)
        <div class="box warnbox">No automated scan has been run yet.</div>
    @else
        <p>Run {{ $date($scan['finished_at']) }} by the server against its own public addresses, without logging in: <strong>{{ $scan['passed'] }} passed, {{ $scan['failed'] }} failed, {{ $scan['warnings'] }} {{ \Illuminate\Support\Str::plural('warning', $scan['warnings']) }}.</strong></p>
        <table class="grid">
            <tr><th style="width: 16%">Area</th><th style="width: 9%">Passed</th><th style="width: 9%">Failed</th><th style="width: 10%">Warnings</th><th>Not passed</th></tr>
            @foreach ($areas as $area => $rows)
                <tr>
                    <td><strong>{{ $area }}</strong></td>
                    <td>{{ $rows->where('status', 'pass')->count() }}</td>
                    <td>{{ $rows->where('status', 'fail')->count() }}</td>
                    <td>{{ $rows->where('status', 'warn')->count() }}</td>
                    <td class="small">{{ $rows->where('status', '!=', 'pass')->pluck('check')->implode('; ') ?: '—' }}</td>
                </tr>
            @endforeach
        </table>

        <h3>All checks</h3>
        <table class="grid">
            <tr><th style="width: 14%">Area</th><th style="width: 34%">Check</th><th>Detail</th><th style="width: 10%">Result</th></tr>
            @foreach ($scan['results'] as $row)
                <tr><td>{{ $row['area'] }}</td><td>{{ $row['check'] }}</td><td class="mono">{{ \Illuminate\Support\Str::limit($row['detail'], 160) }}</td><td>{!! $badge($row['status']) !!}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>6. Performance</h2>
    @if (! $scan || $scan['performance'] === [])
        <div class="box warnbox">No performance measurement yet.</div>
    @else
        <table class="grid">
            <tr><th>Page</th><th>Requests</th><th>At a time</th><th>Requests / s</th><th>Typical (p50)</th><th>95 % within</th><th>99 % within</th><th>Errors</th></tr>
            @foreach ($scan['performance'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td><td>{{ $row['requests'] }}</td><td>{{ $row['concurrency'] }}</td><td>{{ $row['per_second'] }}</td>
                    <td>{{ $row['p50'] }} ms</td><td>{{ $row['p95'] }} ms</td><td>{{ $row['p99'] }} ms</td><td>{{ $row['failed'] }}</td>
                </tr>
            @endforeach
        </table>
        <p class="small">Measured from the server itself, so these times are the server’s own work, without internet distance. Customers and partners also see their network time to the server (typically 20–200 ms, plus about three round trips to open a new secure connection).</p>
    @endif

    <h2 class="break">7. Manual tests</h2>
    <p>Logged in with one user per role on a test environment. Each test tries something a user must not be able to do.</p>
    @foreach ($groups as $group)
        <h3>{{ $group['title'] }}</h3>
        <table class="grid">
            <tr><th>Test</th><th style="width: 26%">Expected</th><th style="width: 12%">Result</th></tr>
            @foreach ($group['tests'] as $test)
                <tr>
                    <td>{{ $test['test'] }}@if ($test['note'])<br><span class="small">{{ $test['note'] }}</span>@endif</td>
                    <td>{{ $test['expected'] }}</td>
                    <td>{!! $badge($test['status']) !!}</td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <h2>8. Decisions for the client</h2>
    <p>Business choices that affect security. We recommend changing all of them.</p>
    <table class="grid">
        <tr><th class="w-id">#</th><th>Today</th><th>Recommendation</th><th class="w-st">Decision</th></tr>
        @foreach ($decisions as $decision)
            <tr>
                <td>{{ $decision['id'] }}</td>
                <td>{{ $decision['today'] }}</td>
                <td>{{ $decision['recommendation'] }}@if ($decision['note'])<br><span class="small">{{ $decision['note'] }}</span>@endif</td>
                <td>{!! $badge($decision['status']) !!}</td>
            </tr>
        @endforeach
    </table>

    <h2>9. Next steps</h2>
    @php
        $steps = [];
        $fixed = collect($findings)->where('status', 'fixed')->pluck('id');
        $open = collect($findings)->where('status', 'open')->pluck('id');
        if ($fixed->isNotEmpty()) $steps[] = 'Deploy and verify the fixes for '.$fixed->implode(', ').', then run the automated scan again.';
        if ($open->isNotEmpty()) $steps[] = 'Resolve '.$open->implode(', ').'.';
        if (! $scan || $scan['failed'] > 0) $steps[] = $scan ? 'Fix the '.$scan['failed'].' failed automated check(s) and run the scan again.' : 'Run the automated scan.';
        if ($tests['fail'] > 0) $steps[] = 'Fix the '.$tests['fail'].' failed manual test(s) and test them again.';
        if ($tests['untested'] > 0) $steps[] = 'Complete the '.$tests['untested'].' manual test(s) not yet done.';
        $pending = collect($decisions)->where('status', 'pending')->pluck('id');
        if ($pending->isNotEmpty()) $steps[] = 'Decide '.$pending->implode(', ').' (section 8).';
    @endphp
    @if ($steps === [])
        <div class="box good">All findings are verified, every test has been done and all decisions are made.</div>
    @else
        <ol>
            @foreach ($steps as $step)<li>{{ $step }}</li>@endforeach
        </ol>
    @endif
</body>
</html>
