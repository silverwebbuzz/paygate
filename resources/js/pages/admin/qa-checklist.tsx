import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ExternalLink, Play, RotateCw } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { PgButton } from '@/components/pg/button';
import { Panel } from '@/components/pg/data-table';
import { Field, SelectInput, TextInput } from '@/components/pg/field';
import { KpiGrid, StatTile } from '@/components/pg/kpi-card';
import { PageHeader } from '@/components/pg/page-header';
import { Segmented } from '@/components/pg/segmented';
import { formatDateTime, formatRelative } from '@/lib/dates';
import type { Tone } from '@/lib/status';
import { TONE_CLASSES } from '@/lib/status';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Item = {
    key: string;
    name: string;
    url: string | null;
    address: string | null;
    description: string;
    steps: string[];
    login: string[];
    tests: string[];
};

type Section = { key: string; title: string; items: Item[] };

type AutoStatus =
    | 'queued'
    | 'running'
    | 'passed'
    | 'failed'
    | 'missing'
    | 'error'
    | 'stuck';

type Result = {
    manual_status: 'pass' | 'fail' | null;
    manual_note: string | null;
    tested_by: string | null;
    tested_at: string | null;
    auto_status: AutoStatus | null;
    auto_summary: string | null;
    auto_tests:
        | { test: string; status: string; message: string | null }[]
        | null;
    auto_output: string | null;
    auto_requested_at: string | null;
    auto_finished_at: string | null;
};

type Props = {
    sections: Section[];
    logins: Record<string, { label: string; email: string | null }>;
    results: Record<string, Result>;
    runner: { problem: string | null };
    partners: { id: string; code: string; name: string }[];
    created:
        | { kind: 'payin'; reference: string; url: string }
        | { kind: 'payout'; reference: string; branch: string | null }
        | null;
};

type Filter = 'all' | 'untested' | 'failed' | 'passed';

const AUTO: Record<AutoStatus, { label: string; tone: Tone; icon: string }> = {
    queued: { label: 'Queued', tone: 'nt', icon: '◷' },
    running: { label: 'Running', tone: 'in', icon: '◷' },
    passed: { label: 'Passed', tone: 'ok', icon: '✓' },
    failed: { label: 'Failed', tone: 'er', icon: '✕' },
    missing: { label: 'No test found', tone: 'wn', icon: '!' },
    error: { label: 'Could not run', tone: 'er', icon: '!' },
    stuck: { label: 'Stuck', tone: 'wn', icon: '!' },
};

const HEADERS = [
    'URL',
    'Description',
    'What to test and how',
    'Login with',
    'Tested?',
    'Automated tests',
];

/**
 * QA Checklist (Admin, local and staging only): every feature with where it
 * is, how to test it and who to log in as. Testers mark Pass / Fail with a
 * note; "Re-run" repeats the item's automated tests in the background. The
 * list itself is app/Domain/Qa/Checklist.php.
 */
export default function QaChecklist(props: Props) {
    const { sections, results, runner } = props;
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [filter, setFilter] = useState<Filter>('all');
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState<Record<string, boolean>>({});

    const running = Object.values(results).some(
        (result) =>
            result.auto_status === 'queued' || result.auto_status === 'running',
    );

    // While tests run, refresh the results every 3 seconds.
    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['results'] }),
            3000,
        );

        return () => window.clearInterval(timer);
    }, [running]);

    const items = sections.flatMap((section) => section.items);
    const totals = useMemo(() => {
        const count = (test: (result: Result | undefined) => boolean) =>
            items.filter((item) => test(results[item.key])).length;

        return {
            pass: count((result) => result?.manual_status === 'pass'),
            fail: count((result) => result?.manual_status === 'fail'),
            untested: count((result) => !result?.manual_status),
            autoPassed: count((result) => result?.auto_status === 'passed'),
            autoFailed: count((result) =>
                ['failed', 'error', 'missing'].includes(
                    result?.auto_status ?? '',
                ),
            ),
        };
    }, [items, results]);

    const visible = (item: Item) => {
        const result = results[item.key];
        const text =
            `${item.name} ${item.description} ${item.url ?? ''} ${item.steps.join(' ')}`.toLowerCase();

        if (search && !text.includes(search.toLowerCase())) {
            return false;
        }

        return filter === 'all'
            ? true
            : filter === 'untested'
              ? !result?.manual_status
              : filter === 'failed'
                ? result?.manual_status === 'fail' ||
                  result?.auto_status === 'failed' ||
                  result?.auto_status === 'error'
                : result?.manual_status === 'pass';
    };

    return (
        <>
            <Head title="QA Checklist" />
            <PageHeader
                title="QA Checklist"
                description="Every feature built so far: where it is, what to test and how, and who to log in as. Mark each row after testing it; Re-run repeats its automated tests."
                actions={
                    <PgButton
                        variant="primary"
                        disabled={runner.problem !== null || running}
                        onClick={() =>
                            router.post(
                                admin.qaChecklist.run().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Play className="size-3.5" /> Run all automated tests
                    </PgButton>
                }
            />

            {runner.problem && (
                <Panel className="px-4 py-3 text-[13px] text-er">
                    Automated tests can’t run here: {runner.problem}
                </Panel>
            )}
            {errors.run && (
                <Panel className="px-4 py-3 text-[13px] text-er">
                    {errors.run}
                </Panel>
            )}

            <KpiGrid>
                <StatTile
                    label="Checks"
                    value={String(items.length)}
                    icon="≡"
                />
                <StatTile
                    label="Tested: pass"
                    value={String(totals.pass)}
                    icon="✓"
                    tone="ok"
                />
                <StatTile
                    label="Tested: fail"
                    value={String(totals.fail)}
                    icon="✕"
                    tone="er"
                />
                <StatTile
                    label="Not tested yet"
                    value={String(totals.untested)}
                    icon="○"
                    tone="wn"
                />
                <StatTile
                    label="Automated: passed / failed"
                    value={`${totals.autoPassed} / ${totals.autoFailed}`}
                    icon="⚙"
                    tone={totals.autoFailed > 0 ? 'er' : 'in'}
                />
            </KpiGrid>

            <div className="grid gap-3 lg:grid-cols-[1fr_1.2fr]">
                <Panel className="flex flex-col gap-2 p-4 text-[13px]">
                    <div className="text-sm font-semibold">
                        Before you start
                    </div>
                    <p className="text-tx2">
                        Demo logins exist only on a local machine; the password
                        for all of them is <b className="font-mono">password</b>
                        . On staging, use a user with the role named in “Login
                        with”. E-mails (invitations, alerts, resets) arrive in
                        Mailpit locally (
                        <a
                            className="text-ac"
                            href="http://localhost:8025"
                            target="_blank"
                            rel="noreferrer"
                        >
                            localhost:8025
                        </a>
                        ).
                    </p>
                    <p className="text-tx2">
                        Automated tests run in the background on a separate test
                        database (Horizon must be running); your data is never
                        touched.
                    </p>
                </Panel>
                <TestData partners={props.partners} created={props.created} />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <TextInput
                    className="h-9 max-w-[280px]"
                    placeholder="Search checks…"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                />
                <Segmented<Filter>
                    value={filter}
                    onChange={setFilter}
                    options={[
                        { value: 'all', label: 'All' },
                        { value: 'untested', label: 'Not tested' },
                        { value: 'failed', label: 'Failed' },
                        { value: 'passed', label: 'Passed' },
                    ]}
                />
            </div>

            {sections.map((section) => {
                const rows = section.items.filter(visible);

                if (rows.length === 0) {
                    return null;
                }

                const tested = section.items.filter(
                    (item) => results[item.key]?.manual_status,
                ).length;

                return (
                    <Panel key={section.key} className="overflow-hidden">
                        <div className="flex items-center justify-between gap-2 border-b border-ln px-4 py-3">
                            <div className="text-sm font-semibold">
                                {section.title}
                            </div>
                            <div className="text-xs text-tx3">
                                {tested} of {section.items.length} tested
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[1180px] text-left text-[13px]">
                                <thead>
                                    <tr className="border-b border-ln bg-sf2 text-xs text-tx3">
                                        {HEADERS.map((header) => (
                                            <th
                                                key={header}
                                                className="px-3 py-2 font-medium"
                                            >
                                                {header}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((item) => (
                                        <Fragment key={item.key}>
                                            <Row
                                                item={item}
                                                result={results[item.key]}
                                                logins={props.logins}
                                                canRun={runner.problem === null}
                                                open={!!open[item.key]}
                                                onToggle={() =>
                                                    setOpen({
                                                        ...open,
                                                        [item.key]:
                                                            !open[item.key],
                                                    })
                                                }
                                            />
                                            {open[item.key] && (
                                                <Details
                                                    result={results[item.key]}
                                                />
                                            )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>
                );
            })}
        </>
    );
}

function Row({
    item,
    result,
    logins,
    canRun,
    open,
    onToggle,
}: {
    item: Item;
    result: Result | undefined;
    logins: Props['logins'];
    canRun: boolean;
    open: boolean;
    onToggle: () => void;
}) {
    const auto = result?.auto_status ? AUTO[result.auto_status] : null;
    const busy =
        result?.auto_status === 'queued' || result?.auto_status === 'running';

    return (
        <tr className="border-b border-ln2 align-top last:border-0">
            <td className="w-[190px] px-3 py-3">
                <UrlCell item={item} />
            </td>
            <td className="w-[260px] px-3 py-3">
                <div className="font-semibold">{item.name}</div>
                <p className="mt-1 text-tx2">{item.description}</p>
            </td>
            <td className="px-3 py-3">
                <ol className="list-decimal space-y-1 pl-4 text-tx2">
                    {item.steps.map((step) => (
                        <li key={step}>{step}</li>
                    ))}
                </ol>
            </td>
            <td className="w-[170px] px-3 py-3">
                <div className="flex flex-col gap-1.5">
                    {item.login.map((key) => (
                        <div key={key} className="leading-tight">
                            <div>{logins[key]?.label ?? key}</div>
                            {logins[key]?.email && (
                                <div className="font-mono text-[11.5px] text-tx3">
                                    {logins[key].email}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </td>
            <td className="w-[190px] px-3 py-3">
                <Manual itemKey={item.key} result={result} />
            </td>
            <td className="w-[170px] px-3 py-3">
                {item.tests.length === 0 ? (
                    <span className="text-xs text-tx3">Manual only</span>
                ) : (
                    <div className="flex flex-col items-start gap-1.5">
                        {auto ? (
                            <Pill tone={auto.tone}>
                                {auto.icon} {auto.label}
                            </Pill>
                        ) : (
                            <span className="text-xs text-tx3">
                                Not run yet
                            </span>
                        )}
                        {result?.auto_summary && !busy && (
                            <span className="text-xs text-tx2">
                                {result.auto_summary}
                            </span>
                        )}
                        {result?.auto_finished_at && !busy && (
                            <span className="text-[11.5px] text-tx3">
                                {formatRelative(result.auto_finished_at)}
                            </span>
                        )}
                        <div className="flex items-center gap-2">
                            <PgButton
                                className="h-7 px-2 text-xs"
                                disabled={!canRun || busy}
                                onClick={() =>
                                    router.post(
                                        admin.qaChecklist.run().url,
                                        { key: item.key },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RotateCw
                                    className={cn(
                                        'size-3',
                                        busy && 'animate-spin',
                                    )}
                                />
                                Re-run
                            </PgButton>
                            {result?.auto_tests && !busy && (
                                <button
                                    type="button"
                                    onClick={onToggle}
                                    className="text-xs font-medium text-ac"
                                >
                                    {open ? 'Hide' : 'Details'}
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </td>
        </tr>
    );
}

function UrlCell({ item }: { item: Item }) {
    if (!item.url || !item.address) {
        return <span className="text-xs text-tx3">No page</span>;
    }

    const host = item.url.startsWith('api:')
        ? 'API'
        : item.url.startsWith('pay:')
          ? 'Payment page'
          : null;
    const path = item.url.replace(/^(api|pay):/, '');
    // Paths with a placeholder ({token}, {id}) need a real value first.
    const clickable = !path.includes('{');

    return (
        <div className="flex flex-col items-start gap-1">
            {host && (
                <span className="rounded bg-sf2 px-1.5 py-px text-[10.5px] font-medium text-tx2">
                    {host}
                </span>
            )}
            {clickable ? (
                <a
                    href={item.address}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 font-mono text-xs break-all text-ac"
                >
                    {path} <ExternalLink className="size-3 flex-none" />
                </a>
            ) : (
                <span className="font-mono text-xs break-all">{path}</span>
            )}
        </div>
    );
}

function Manual({
    itemKey,
    result,
}: {
    itemKey: string;
    result: Result | undefined;
}) {
    const status = result?.manual_status ?? null;
    const [note, setNote] = useState(result?.manual_note ?? '');

    useEffect(() => setNote(result?.manual_note ?? ''), [result?.manual_note]);

    const save = (next: 'pass' | 'fail' | null, nextNote = note) =>
        router.put(
            admin.qaChecklist.mark(itemKey).url,
            { status: next, note: nextNote || null },
            { preserveScroll: true, only: ['results'] },
        );

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex gap-1">
                {(['pass', 'fail'] as const).map((value) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => save(status === value ? null : value)}
                        className={cn(
                            'h-7 rounded-md border px-2.5 text-xs font-medium',
                            status === value
                                ? value === 'pass'
                                    ? 'border-transparent bg-okb text-ok'
                                    : 'border-transparent bg-erb text-er'
                                : 'border-ln bg-sf text-tx2 hover:bg-sf2',
                        )}
                    >
                        {value === 'pass' ? '✓ Pass' : '✕ Fail'}
                    </button>
                ))}
            </div>
            {status ? (
                <>
                    <textarea
                        value={note}
                        placeholder="Note (optional)"
                        rows={2}
                        onChange={(event) => setNote(event.target.value)}
                        onBlur={() =>
                            note !== (result?.manual_note ?? '') &&
                            save(status, note)
                        }
                        className="w-full rounded-md border border-ln bg-sf px-2 py-1 text-xs outline-none focus:border-ac"
                    />
                    <span className="text-[11.5px] text-tx3">
                        {result?.tested_by} ·{' '}
                        {formatDateTime(result?.tested_at)}
                    </span>
                </>
            ) : (
                <span className="text-xs text-tx3">Not tested yet</span>
            )}
        </div>
    );
}

function Details({ result }: { result: Result | undefined }) {
    if (!result?.auto_tests) {
        return null;
    }

    return (
        <tr className="border-b border-ln2 bg-sf2/60">
            <td colSpan={HEADERS.length} className="px-4 py-3">
                <div className="flex flex-col gap-1">
                    {result.auto_tests.map((test) => (
                        <div key={test.test} className="text-xs">
                            <span
                                className={cn(
                                    'mr-2 font-semibold',
                                    test.status === 'passed'
                                        ? 'text-ok'
                                        : test.status === 'failed'
                                          ? 'text-er'
                                          : 'text-tx3',
                                )}
                            >
                                {test.status === 'passed'
                                    ? '✓'
                                    : test.status === 'failed'
                                      ? '✕'
                                      : '–'}
                            </span>
                            <span className="font-mono">{test.test}</span>
                            {test.message && (
                                <pre className="mt-1 mb-2 max-h-60 overflow-auto rounded-md bg-sf p-2 text-[11px] whitespace-pre-wrap text-er">
                                    {test.message}
                                </pre>
                            )}
                        </div>
                    ))}
                    {result.auto_tests.length === 0 && result.auto_output && (
                        <pre className="max-h-60 overflow-auto rounded-md bg-sf p-2 text-[11px] whitespace-pre-wrap">
                            {result.auto_output}
                        </pre>
                    )}
                </div>
            </td>
        </tr>
    );
}

function TestData({
    partners,
    created,
}: {
    partners: Props['partners'];
    created: Props['created'];
}) {
    const form = useForm({
        kind: 'payin' as 'payin' | 'payout',
        partner_id: partners[0]?.id ?? '',
        amount: '500',
    });

    return (
        <Panel className="flex flex-col gap-3 p-4">
            <div>
                <div className="text-sm font-semibold">Test data</div>
                <p className="mt-1 text-[13px] text-tx2">
                    Creates a pay-in (with its payment link) or a payout through
                    the same code as the Partner API, so you don’t need to sign
                    API calls.
                </p>
            </div>
            <form
                className="grid items-end gap-2 sm:grid-cols-[auto_1fr_110px_auto]"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(admin.qaChecklist.sample().url, {
                        preserveScroll: true,
                    });
                }}
            >
                <Segmented<'payin' | 'payout'>
                    value={form.data.kind}
                    onChange={(value) => form.setData('kind', value)}
                    options={[
                        { value: 'payin', label: 'Pay-in' },
                        { value: 'payout', label: 'Payout' },
                    ]}
                />
                <Field label="Partner" error={form.errors.partner_id}>
                    <SelectInput
                        value={form.data.partner_id}
                        onChange={(event) =>
                            form.setData('partner_id', event.target.value)
                        }
                    >
                        {partners.length === 0 && (
                            <option value="">No active partner</option>
                        )}
                        {partners.map((partner) => (
                            <option key={partner.id} value={partner.id}>
                                {partner.code} · {partner.name}
                            </option>
                        ))}
                    </SelectInput>
                </Field>
                <Field label="Amount (₹)">
                    <TextInput
                        inputMode="decimal"
                        value={form.data.amount}
                        onChange={(event) =>
                            form.setData('amount', event.target.value)
                        }
                    />
                </Field>
                <PgButton
                    type="submit"
                    variant="primary"
                    className="h-10"
                    disabled={form.processing || partners.length === 0}
                >
                    Create
                </PgButton>
            </form>
            {form.errors.amount && (
                <p className="text-xs text-er">{form.errors.amount}</p>
            )}
            {created && (
                <div className="rounded-lg bg-okb px-3 py-2 text-[13px] text-ok">
                    {created.kind === 'payin' ? (
                        <>
                            Pay-in{' '}
                            <b className="font-mono">{created.reference}</b>{' '}
                            created.{' '}
                            <a
                                href={created.url}
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium underline"
                            >
                                Open the payment page
                            </a>
                        </>
                    ) : (
                        <>
                            Payout{' '}
                            <b className="font-mono">{created.reference}</b>{' '}
                            created and assigned to branch{' '}
                            <b>{created.branch}</b>: it is in that branch’s
                            Manual Payout.
                        </>
                    )}
                </div>
            )}
        </Panel>
    );
}

function Pill({ tone, children }: { tone: Tone; children: React.ReactNode }) {
    return (
        <span
            className={cn(
                'inline-flex h-[22px] items-center gap-1 rounded-md px-2 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[tone],
            )}
        >
            {children}
        </span>
    );
}
