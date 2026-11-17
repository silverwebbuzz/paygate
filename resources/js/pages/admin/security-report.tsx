import { Head, router, useForm } from '@inertiajs/react';
import { Download, Play, RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, TextArea, TextInput } from '@/components/pg/field';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { formatDateTime } from '@/lib/dates';
import type { Tone } from '@/lib/status';
import { TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Details = {
    client: string | null;
    round: string | null;
    environment: string | null;
    prepared_by: string | null;
    note: string | null;
};

type ScanRow = {
    id: string;
    status: 'queued' | 'running' | 'done' | 'error' | 'stuck';
    passed: number;
    failed: number;
    warnings: number;
    error: string | null;
    requested_by: string;
    requested_at: string;
    finished_at: string | null;
};

type Check = { status: string; area: string; check: string; detail: string };

type Perf = {
    name: string;
    url: string;
    requests: number;
    concurrency: number;
    per_second: number;
    p50: number;
    p95: number;
    p99: number;
    failed: number;
};

type Finding = {
    key: string;
    id: string;
    title: string;
    detail: string;
    severity: string;
    fix: string;
    default: string;
};

type Decision = {
    key: string;
    id: string;
    today: string;
    recommendation: string;
};

type Group = {
    key: string;
    title: string;
    tests: { key: string; test: string; expected: string }[];
};

type Entry = {
    status: string | null;
    note: string | null;
    updated_by: string | null;
    updated_at: string | null;
};

type Props = {
    details: Details;
    target: { portal: string; api: string; pay: string };
    load: { requests: number; concurrency: number };
    scans: ScanRow[];
    running: boolean;
    latest: {
        finished_at: string | null;
        results: Check[];
        performance: Perf[];
    } | null;
    findings: Finding[];
    decisions: Decision[];
    groups: Group[];
    entries: Record<string, Entry>;
};

type Tab = 'details' | 'scan' | 'tests' | 'findings' | 'preview';

const LABELS: Record<string, string> = {
    pass: 'Pass',
    fail: 'Fail',
    warn: 'Warning',
    na: 'N/A',
    open: 'Open',
    fixed: 'Fixed, not deployed',
    verified: 'Verified',
    accepted: 'Accepted',
    pending: 'Pending',
    agreed: 'Agreed',
    declined: 'Declined',
    queued: 'Queued',
    running: 'Running',
    done: 'Done',
    error: 'Error',
    stuck: 'Stuck',
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
    info: 'Info',
};

const TONES: Record<string, Tone> = {
    pass: 'ok',
    verified: 'ok',
    agreed: 'ok',
    done: 'ok',
    fail: 'er',
    open: 'er',
    error: 'er',
    declined: 'er',
    critical: 'er',
    high: 'er',
    warn: 'wn',
    fixed: 'wn',
    pending: 'wn',
    stuck: 'wn',
    medium: 'wn',
    queued: 'in',
    running: 'in',
    low: 'in',
};

function Badge({ value }: { value: string | null }) {
    return (
        <span
            className={cn(
                'inline-flex h-5 items-center rounded px-1.5 text-[11px] font-semibold whitespace-nowrap',
                TONE_CLASSES[value ? (TONES[value] ?? 'nt') : 'nt'],
            )}
        >
            {value ? (LABELS[value] ?? value) : 'Not tested'}
        </span>
    );
}

/**
 * Admin › Testing › Security Report (super admins only): the security and
 * performance report for the client — automated scan, manual tests,
 * findings and decisions — previewed here and downloaded as PDF.
 */
export default function SecurityReport(props: Props) {
    const { scans, running, latest, entries } = props;
    const [tab, setTab] = useState<Tab>('details');

    // While a scan runs, refresh until it finishes.
    useEffect(() => {
        if (!running) return;

        const timer = window.setInterval(
            () => router.reload({ only: ['scans', 'running', 'latest'] }),
            4000,
        );

        return () => window.clearInterval(timer);
    }, [running]);

    const tests = props.groups.flatMap((group) => group.tests);
    const tested = tests.filter((test) => entries[test.key]?.status);
    const failedTests = tests.filter(
        (test) => entries[test.key]?.status === 'fail',
    );
    const lastScan = scans.find((scan) => scan.status === 'done');
    const openFindings = props.findings.filter((finding) =>
        ['open', 'fixed'].includes(
            entries[finding.key]?.status ?? finding.default,
        ),
    );

    return (
        <>
            <Head title="Security Report" />
            <PageHeader
                title="Security Report"
                description="The security and performance report for the client: run the automated scan, record the manual tests, set the status of findings and decisions, then download the PDF."
                actions={
                    <>
                        <PgButton
                            disabled={running}
                            onClick={() =>
                                router.post(
                                    admin.securityReport.scan().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {running ? (
                                <RotateCw className="size-3.5 animate-spin" />
                            ) : (
                                <Play className="size-3.5" />
                            )}
                            {running ? 'Scan running…' : 'Run scan'}
                        </PgButton>
                        <a
                            href={admin.securityReport.download().url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-[7px] border border-transparent bg-brand px-3 text-[13px] font-medium whitespace-nowrap text-white hover:brightness-110"
                        >
                            <Download className="size-3.5" /> Download PDF
                        </a>
                    </>
                }
            />

            <KpiGrid>
                <StatTile
                    label="Automated checks passed"
                    value={
                        lastScan
                            ? `${lastScan.passed} / ${lastScan.passed + lastScan.failed + lastScan.warnings}`
                            : '—'
                    }
                    icon="⚙"
                    tone={lastScan && lastScan.failed > 0 ? 'er' : 'ok'}
                />
                <StatTile
                    label="Manual tests done"
                    value={`${tested.length} / ${tests.length}`}
                    icon="✓"
                    tone={tested.length === tests.length ? 'ok' : 'wn'}
                />
                <StatTile
                    label="Manual tests failed"
                    value={String(failedTests.length)}
                    icon="✕"
                    tone={failedTests.length > 0 ? 'er' : 'ok'}
                />
                <StatTile
                    label="Findings not yet verified"
                    value={String(openFindings.length)}
                    icon="!"
                    tone={openFindings.length > 0 ? 'wn' : 'ok'}
                />
            </KpiGrid>

            <Segmented<Tab>
                value={tab}
                onChange={setTab}
                options={[
                    { value: 'details', label: 'Report details' },
                    { value: 'scan', label: 'Automated scan' },
                    { value: 'tests', label: 'Manual tests' },
                    { value: 'findings', label: 'Findings & decisions' },
                    { value: 'preview', label: 'Preview & PDF' },
                ]}
            />

            {tab === 'details' && <DetailsForm {...props} />}
            {tab === 'scan' && (
                <ScanTab
                    scans={scans}
                    latest={latest}
                    load={props.load}
                    target={props.target}
                />
            )}
            {tab === 'tests' && (
                <Panel className="overflow-hidden">
                    {props.groups.map((group) => (
                        <div
                            key={group.key}
                            className="border-b border-ln last:border-0"
                        >
                            <div className="bg-sf2 px-4 py-2 text-[13px] font-semibold">
                                {group.title}
                            </div>
                            {group.tests.map((test) => (
                                <Row
                                    key={test.key}
                                    entryKey={test.key}
                                    entry={entries[test.key]}
                                    options={['pass', 'fail', 'na']}
                                    fallback={null}
                                >
                                    <div className="text-[13px]">
                                        {test.test}
                                    </div>
                                    <div className="text-xs text-tx3">
                                        Expected: {test.expected}
                                    </div>
                                </Row>
                            ))}
                        </div>
                    ))}
                </Panel>
            )}
            {tab === 'findings' && (
                <>
                    <Panel className="overflow-hidden">
                        <div className="bg-sf2 px-4 py-2 text-[13px] font-semibold">
                            Findings
                        </div>
                        {props.findings.map((finding) => (
                            <Row
                                key={finding.key}
                                entryKey={finding.key}
                                entry={entries[finding.key]}
                                options={[
                                    'open',
                                    'fixed',
                                    'verified',
                                    'accepted',
                                ]}
                                fallback={finding.default}
                            >
                                <div className="flex items-center gap-2 text-[13px] font-medium">
                                    {finding.id} · {finding.title}
                                    <Badge value={finding.severity} />
                                </div>
                                <div className="text-xs text-tx2">
                                    {finding.detail}
                                </div>
                                <div className="text-xs text-tx3">
                                    Fix: {finding.fix}
                                </div>
                            </Row>
                        ))}
                    </Panel>
                    <Panel className="overflow-hidden">
                        <div className="bg-sf2 px-4 py-2 text-[13px] font-semibold">
                            Decisions for the client
                        </div>
                        {props.decisions.map((decision) => (
                            <Row
                                key={decision.key}
                                entryKey={decision.key}
                                entry={entries[decision.key]}
                                options={['pending', 'agreed', 'declined']}
                                fallback="pending"
                            >
                                <div className="text-[13px] font-medium">
                                    {decision.id} · {decision.today}
                                </div>
                                <div className="text-xs text-tx3">
                                    Recommendation: {decision.recommendation}
                                </div>
                            </Row>
                        ))}
                    </Panel>
                </>
            )}
            {tab === 'preview' && (
                <Panel className="overflow-hidden">
                    <iframe
                        key={
                            JSON.stringify(entries) +
                            (latest?.finished_at ?? '')
                        }
                        src={admin.securityReport.preview().url}
                        title="Security report preview"
                        className="block h-[78vh] w-full bg-white"
                    />
                </Panel>
            )}
        </>
    );
}

function DetailsForm({ details }: Props) {
    const form = useForm({
        client: details.client ?? '',
        round: details.round ?? '1',
        environment: details.environment ?? '',
        prepared_by: details.prepared_by ?? '',
        note: details.note ?? '',
    });

    return (
        <Panel className="p-4">
            <form
                className="grid gap-3 md:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(admin.securityReport.details().url, {
                        preserveScroll: true,
                    });
                }}
            >
                <Field label="Prepared for (client)" error={form.errors.client}>
                    <TextInput
                        value={form.data.client}
                        onChange={(event) =>
                            form.setData('client', event.target.value)
                        }
                        placeholder="Client name"
                    />
                </Field>
                <Field
                    label="Round"
                    hint="1 = first test, 2 = re-test after fixes, …"
                    error={form.errors.round}
                >
                    <TextInput
                        value={form.data.round}
                        onChange={(event) =>
                            form.setData('round', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Environment"
                    hint="Shown on the cover, e.g. “Live (aidemo.in)”. Empty = the portal address."
                    error={form.errors.environment}
                >
                    <TextInput
                        value={form.data.environment}
                        onChange={(event) =>
                            form.setData('environment', event.target.value)
                        }
                    />
                </Field>
                <Field label="Tested by" error={form.errors.prepared_by}>
                    <TextInput
                        value={form.data.prepared_by}
                        onChange={(event) =>
                            form.setData('prepared_by', event.target.value)
                        }
                        placeholder="Name or company"
                    />
                </Field>
                <div className="md:col-span-2">
                    <Field
                        label="Summary note (optional)"
                        hint="A paragraph shown in the summary of the report."
                        error={form.errors.note}
                    >
                        <TextArea
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="md:col-span-2">
                    <PgButton
                        type="submit"
                        variant="primary"
                        disabled={form.processing}
                    >
                        Save details
                    </PgButton>
                </div>
            </form>
        </Panel>
    );
}

function ScanTab({
    scans,
    latest,
    load,
    target,
}: Pick<Props, 'scans' | 'latest' | 'load' | 'target'>) {
    const [filter, setFilter] = useState<'issues' | 'all'>('issues');
    const results = (latest?.results ?? []).filter(
        (row) => filter === 'all' || row.status !== 'pass',
    );

    return (
        <>
            <Panel className="flex flex-col gap-1.5 p-4 text-[13px] text-tx2">
                <p>
                    The server checks its own settings, then calls its public
                    addresses the way an outsider would: encryption, browser
                    protections, cookies, exposed files, error pages, partner
                    API protection, payment page and login, then a light load (
                    {load.requests} requests per page, {load.concurrency} at a
                    time). It never logs in and creates no data.
                </p>
                <p className="text-xs text-tx3">
                    Targets: {target.portal} · {target.api} · {target.pay}. The
                    API lockout and payment-page rate limit are tripped for the
                    server’s own address for about a minute; partners and
                    customers are not affected.
                </p>
            </Panel>

            <Panel className="overflow-hidden">
                <div className="bg-sf2 px-4 py-2 text-[13px] font-semibold">
                    Recent scans
                </div>
                {scans.length === 0 ? (
                    <div className="px-4 py-3 text-[13px] text-tx3">
                        No scan yet. Press Run scan.
                    </div>
                ) : (
                    <table className="w-full text-[13px]">
                        <tbody>
                            {scans.map((scan) => (
                                <tr
                                    key={scan.id}
                                    className="border-t border-ln"
                                >
                                    <td className="px-4 py-2">
                                        <Badge value={scan.status} />
                                    </td>
                                    <td className="px-4 py-2">
                                        {formatDateTime(scan.requested_at)} ·{' '}
                                        {scan.requested_by}
                                    </td>
                                    <td className="px-4 py-2">
                                        {scan.status === 'done'
                                            ? `${scan.passed} passed · ${scan.failed} failed · ${scan.warnings} warnings`
                                            : (scan.error ?? '')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Panel>

            {latest && (
                <>
                    <div className="flex items-center justify-between">
                        <div className="text-sm font-semibold">
                            Latest results ({formatDateTime(latest.finished_at)}
                            )
                        </div>
                        <Segmented<'issues' | 'all'>
                            value={filter}
                            onChange={setFilter}
                            options={[
                                { value: 'issues', label: 'Not passed' },
                                { value: 'all', label: 'All checks' },
                            ]}
                        />
                    </div>
                    <Panel className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <tbody>
                                {results.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-3 text-ok">
                                            Every check passed.
                                        </td>
                                    </tr>
                                )}
                                {results.map((row, index) => (
                                    <tr
                                        key={index}
                                        className="border-t border-ln first:border-0"
                                    >
                                        <td className="w-24 px-4 py-2">
                                            <Badge value={row.status} />
                                        </td>
                                        <td className="w-32 px-2 py-2 text-tx3">
                                            {row.area}
                                        </td>
                                        <td className="px-2 py-2">
                                            {row.check}
                                        </td>
                                        <td className="px-4 py-2 font-mono text-xs break-all text-tx2">
                                            {row.detail}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Panel>

                    {latest.performance.length > 0 && (
                        <Panel className="overflow-x-auto">
                            <div className="bg-sf2 px-4 py-2 text-[13px] font-semibold">
                                Performance (measured from the server itself)
                            </div>
                            <table className="w-full text-[13px]">
                                <thead className="text-left text-xs text-tx3">
                                    <tr>
                                        <th className="px-4 py-2">Page</th>
                                        <th className="px-2 py-2">Req/s</th>
                                        <th className="px-2 py-2">p50</th>
                                        <th className="px-2 py-2">p95</th>
                                        <th className="px-2 py-2">p99</th>
                                        <th className="px-4 py-2">Errors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {latest.performance.map((row) => (
                                        <tr
                                            key={row.url}
                                            className="border-t border-ln"
                                        >
                                            <td className="px-4 py-2">
                                                {row.name}
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.per_second}
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.p50} ms
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.p95} ms
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.p99} ms
                                            </td>
                                            <td className="px-4 py-2">
                                                {row.failed} / {row.requests}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </Panel>
                    )}
                </>
            )}
        </>
    );
}

/** One test, finding or decision: status buttons and a note, saved at once. */
function Row({
    entryKey,
    entry,
    options,
    fallback,
    children,
}: {
    entryKey: string;
    entry: Entry | undefined;
    options: string[];
    fallback: string | null;
    children: React.ReactNode;
}) {
    const status = entry?.status ?? fallback;
    const [note, setNote] = useState(entry?.note ?? '');

    useEffect(() => setNote(entry?.note ?? ''), [entry?.note]);

    const save = (next: string | null, nextNote = note) =>
        router.put(
            admin.securityReport.mark(entryKey).url,
            { status: next, note: nextNote || null },
            { preserveScroll: true, only: ['entries'] },
        );

    return (
        <div className="grid gap-2 border-t border-ln px-4 py-3 first:border-0 md:grid-cols-[1fr_320px]">
            <div className="flex flex-col gap-0.5">{children}</div>
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap gap-1">
                    {options.map((value) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() =>
                                save(
                                    status === value && fallback === null
                                        ? null
                                        : value,
                                )
                            }
                            className={cn(
                                'h-7 rounded-md border px-2 text-xs font-medium',
                                status === value
                                    ? cn(
                                          'border-transparent',
                                          TONE_CLASSES[TONES[value] ?? 'nt'],
                                      )
                                    : 'border-ln bg-sf text-tx2 hover:bg-sf2',
                            )}
                        >
                            {LABELS[value]}
                        </button>
                    ))}
                </div>
                <textarea
                    value={note}
                    placeholder="Note for the report (optional)"
                    rows={2}
                    onChange={(event) => setNote(event.target.value)}
                    onBlur={() =>
                        note !== (entry?.note ?? '') && save(status, note)
                    }
                    className="w-full rounded-md border border-ln bg-sf px-2 py-1 text-xs outline-none focus:border-ac"
                />
                {entry?.updated_by && (
                    <span className="text-[11.5px] text-tx3">
                        {entry.updated_by} · {formatDateTime(entry.updated_at)}
                    </span>
                )}
            </div>
        </div>
    );
}
