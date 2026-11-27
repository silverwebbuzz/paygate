import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/pg/status-badge';
import { cn } from '@/lib/utils';

/**
 * "Virtual screenshots" for the Guide (Admin): small drawings of the real
 * screens, made with the same colours and status badges, with numbered
 * marks that the guide's text explains. They are pictures only: nothing on
 * them can be clicked or changed.
 */

/** A browser window with the page's address in the bar. */
export function MockFrame({
    url,
    children,
    className,
}: {
    url: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-ln bg-sf shadow-sm select-none',
                className,
            )}
            aria-hidden
        >
            <div className="flex items-center gap-2 border-b border-ln bg-bg px-3 py-2">
                <span className="flex gap-1">
                    <span className="size-2.5 rounded-full bg-er/60" />
                    <span className="size-2.5 rounded-full bg-wn/60" />
                    <span className="size-2.5 rounded-full bg-ok/60" />
                </span>
                <span className="min-w-0 flex-1 truncate rounded-md border border-ln bg-sf px-2 py-0.5 font-mono text-[11px] text-tx3">
                    {url}
                </span>
            </div>
            <div className="p-3 text-[12px]">{children}</div>
        </div>
    );
}

/** A numbered mark, explained under the picture. */
export function Mark({ n, className }: { n: number; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex size-[18px] flex-none items-center justify-center rounded-full bg-brand text-[10px] font-bold text-white ring-2 ring-white',
                className,
            )}
        >
            {n}
        </span>
    );
}

function Btn({
    children,
    primary,
    danger,
    mark,
}: {
    children: ReactNode;
    primary?: boolean;
    danger?: boolean;
    mark?: number;
}) {
    return (
        <span className="relative inline-flex">
            <span
                className={cn(
                    'inline-flex h-6 items-center rounded-md border px-2 text-[11px] font-medium whitespace-nowrap',
                    primary
                        ? 'border-transparent bg-brand text-white'
                        : danger
                          ? 'border-ln bg-sf text-er'
                          : 'border-ln bg-sf text-ac',
                )}
            >
                {children}
            </span>
            {mark !== undefined && (
                <Mark n={mark} className="absolute -top-2 -right-2" />
            )}
        </span>
    );
}

function Box({
    label,
    value,
    mark,
    className,
}: {
    label: string;
    value?: string;
    mark?: number;
    className?: string;
}) {
    return (
        <div className={cn('relative flex flex-col gap-0.5', className)}>
            <span className="text-[10px] text-tx3">{label}</span>
            <span className="flex h-6 items-center rounded-md border border-ln bg-bg px-2 font-mono text-[11px] text-tx2">
                {value ?? ''}
            </span>
            {mark !== undefined && (
                <Mark n={mark} className="absolute -top-1 -right-1" />
            )}
        </div>
    );
}

function Row({ cells, head }: { cells: ReactNode[]; head?: boolean }) {
    return (
        <div
            className={cn(
                'grid items-center gap-2 border-b border-ln px-2 py-1.5 last:border-0',
                head &&
                    'bg-bg text-[10px] font-semibold tracking-wide text-tx3 uppercase',
            )}
            style={{
                gridTemplateColumns: `repeat(${cells.length}, minmax(0, 1fr))`,
            }}
        >
            {cells.map((cell, i) => (
                <div key={i} className="min-w-0 truncate">
                    {cell}
                </div>
            ))}
        </div>
    );
}

function Table({ children }: { children: ReactNode }) {
    return (
        <div className="overflow-hidden rounded-lg border border-ln">
            {children}
        </div>
    );
}

function Tile({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: 'ok' | 'er';
}) {
    return (
        <div className="rounded-lg border border-ln p-2">
            <div className="text-[10px] text-tx3">{label}</div>
            <div
                className={cn(
                    'text-[13px] font-semibold',
                    tone === 'ok' && 'text-ok',
                    tone === 'er' && 'text-er',
                )}
            >
                {value}
            </div>
        </div>
    );
}

/** The customer's payment page. */
export function CheckoutMock() {
    return (
        <MockFrame url="https://pay.(our domain)/pay/…">
            <div className="mx-auto flex max-w-[320px] flex-col gap-2.5">
                <div className="relative rounded-lg bg-brand p-3 text-white">
                    <div className="text-[10px] opacity-80">
                        Secure Checkout · Amount to pay
                    </div>
                    <div className="text-[18px] font-bold">₹5,000.00</div>
                    <Mark n={1} className="absolute top-2 right-2" />
                </div>
                <div className="relative flex gap-1">
                    {['UPI & QR code', 'Bank transfer'].map((method, i) => (
                        <span
                            key={method}
                            className={cn(
                                'flex-1 rounded-md border px-2 py-1 text-center text-[11px]',
                                i === 0
                                    ? 'border-ac bg-acs font-medium text-ac'
                                    : 'border-ln text-tx2',
                            )}
                        >
                            {method}
                        </span>
                    ))}
                    <Mark n={2} className="absolute -top-2 -right-2" />
                </div>
                <div className="relative rounded-lg border border-ln p-2">
                    <div className="text-[10px] text-tx3">Pay to UPI ID</div>
                    <div className="font-mono text-[12px]">branchname@bank</div>
                    <div className="mt-1 text-[10px] text-ac underline">
                        Having trouble paying? Get different details
                    </div>
                    <Mark n={3} className="absolute top-1 right-1" />
                </div>
                <Box
                    label="UTR (12-digit number from your bank app) *"
                    value="626812820491"
                    mark={4}
                />
                <Box label="Payment screenshot *" value="screenshot.jpg" />
                <Btn primary>I have paid · Submit</Btn>
            </div>
        </MockFrame>
    );
}

/** Payments › Deposit › Manual Deposit: one deposit waiting for the branch. */
export function ManualDepositMock() {
    return (
        <MockFrame url="/admin/deposits">
            <div className="flex flex-col gap-2 rounded-lg border border-ln p-2.5">
                <div className="flex items-center justify-between">
                    <span className="font-mono font-medium">
                        PI261116K7M2X9QA
                    </span>
                    <StatusBadge status="payment_submitted" />
                </div>
                <div className="grid grid-cols-3 gap-2 text-[11px]">
                    <div>
                        <div className="text-[10px] text-tx3">Amount</div>
                        ₹5,000.00
                    </div>
                    <div className="relative">
                        <div className="text-[10px] text-tx3">Customer UTR</div>
                        <span className="font-mono">626812820491</span>
                        <Mark n={1} className="absolute -top-1 right-0" />
                    </div>
                    <div>
                        <div className="text-[10px] text-tx3">Account</div>
                        HDFC ··4321
                    </div>
                </div>
                <div className="relative flex items-center gap-1.5">
                    <span className="rounded-md border border-dashed border-ln px-2 py-1 text-[10px] text-tx3">
                        Screenshot
                    </span>
                    <Mark n={2} />
                    <span className="flex-1" />
                    <Btn danger mark={5}>
                        Decline
                    </Btn>
                    <Btn mark={4}>Hold</Btn>
                    <Btn primary mark={3}>
                        Approve
                    </Btn>
                </div>
            </div>
        </MockFrame>
    );
}

/** Payments › Deposit › Manual Entry: the bank statement lines. */
export function StatementMock() {
    return (
        <MockFrame url="/admin/statements">
            <div className="flex flex-col gap-2.5">
                <div className="relative rounded-lg border border-ac bg-acs p-2">
                    <div className="mb-1.5 text-[10px] font-semibold text-ac">
                        New statement line · Credit received in the bank
                    </div>
                    <div className="grid grid-cols-5 items-end gap-1.5">
                        <Box label="Bank account" value="HDFC ··4321" />
                        <Box label="Date" value="16 Nov" />
                        <Box label="UTR" value="626812820491" />
                        <Box label="Credit ₹" value="5000" />
                        <Btn primary>Add line</Btn>
                    </div>
                    <Mark n={1} className="absolute -top-2 -right-2" />
                </div>
                <Table>
                    <Row
                        head
                        cells={[
                            'Date · UTR',
                            'Credit',
                            'Status',
                            'Branch match',
                            'Actions',
                        ]}
                    />
                    <Row
                        cells={[
                            <span className="font-mono">626812820491</span>,
                            '₹5,000.00',
                            <StatusBadge status="approved" label="Approved" />,
                            <StatusBadge status="matched" />,
                            <Btn>Details</Btn>,
                        ]}
                    />
                    <Row
                        cells={[
                            <span className="font-mono">626812820555</span>,
                            '₹2,000.00',
                            <StatusBadge
                                status="payment_submitted"
                                label="Pending"
                            />,
                            <StatusBadge status="matched" />,
                            <Btn primary>Approve</Btn>,
                        ]}
                    />
                    <Row
                        cells={[
                            <span className="font-mono">626812820777</span>,
                            '₹4,800.00',
                            <span className="relative inline-flex items-center gap-1">
                                <StatusBadge status="unsettled" />
                                <Mark n={2} />
                            </span>,
                            <StatusBadge status="none" />,
                            <Btn mark={3}>Match</Btn>,
                        ]}
                    />
                </Table>
            </div>
        </MockFrame>
    );
}

/** Payments › Deposit › Unsettled UTR: the case drawer opened by Match. */
export function CaseMock() {
    return (
        <MockFrame url="/admin/unsettled?case=RC261116…">
            <div className="ml-auto flex max-w-[380px] flex-col gap-2 rounded-lg border border-ln p-2.5 shadow-sm">
                <div className="relative flex items-center justify-between">
                    <div>
                        <div className="text-[10px] text-tx3">
                            Unsettled case
                        </div>
                        <div className="font-mono font-medium">
                            RC261116P4ZQ8M2D
                        </div>
                    </div>
                    <StatusBadge status="open" />
                    <Mark n={1} className="absolute -top-1 left-28" />
                </div>
                <div className="relative rounded-md bg-wnb px-2 py-1 text-[11px] text-wn">
                    Amount mismatch: the bank shows ₹4,800.00; PI261116K7M2X9QA
                    is for ₹5,000.00.
                    <Mark n={2} className="absolute -top-2 -right-2" />
                </div>
                <div className="text-[10px] font-semibold tracking-wide text-tx3 uppercase">
                    Link to a deposit
                </div>
                <div className="relative">
                    <Box
                        label=""
                        value="Or find by transaction id, order id or UTR"
                    />
                    <Mark n={3} className="absolute -top-1 -right-1" />
                </div>
                <div className="flex items-center justify-between rounded-md border border-ln p-2">
                    <div>
                        <div className="font-mono text-[11px]">
                            PI261116W2N5R7TA
                        </div>
                        <div className="text-[10px] text-tx3">
                            ₹4,800.00 · Pending
                        </div>
                    </div>
                    <Btn primary mark={4}>
                        Link
                    </Btn>
                </div>
                <div className="flex items-center justify-between rounded-md border border-ln p-2">
                    <div>
                        <div className="font-mono text-[11px]">
                            PI261116K7M2X9QA
                        </div>
                        <div className="text-[10px] text-er">
                            ₹5,000.00 · Pending
                        </div>
                    </div>
                    <span className="relative max-w-[150px] text-right text-[10px] text-tx3">
                        Different amount: can’t be approved, decline it
                        <Mark n={5} className="absolute -top-2 -right-2" />
                    </span>
                </div>
                <div className="relative flex gap-1.5">
                    <Btn>Not a customer payment</Btn>
                    <Btn>Returned to customer</Btn>
                    <Mark n={6} className="absolute -top-2 -right-2" />
                </div>
            </div>
        </MockFrame>
    );
}

/** Payments › Withdrawal › Manual Payout: a payout in the branch's queue. */
export function PayoutMock() {
    return (
        <MockFrame url="/admin/payouts">
            <div className="flex flex-col gap-2">
                <Table>
                    <Row
                        head
                        cells={[
                            'Payout',
                            'Beneficiary',
                            'Amount',
                            'Status',
                            'Actions',
                        ]}
                    />
                    <Row
                        cells={[
                            <span className="font-mono">PO261116A8B3C4DE</span>,
                            'R. Kumar · SBIN···',
                            '₹10,000.00',
                            <StatusBadge status="assigned" />,
                            <Btn primary mark={1}>
                                Start
                            </Btn>,
                        ]}
                    />
                    <Row
                        cells={[
                            <span className="font-mono">PO261116F6G7H8JK</span>,
                            'S. Patel · HDFC···',
                            '₹25,000.00',
                            <StatusBadge status="processing" />,
                            <span className="flex gap-1">
                                <Btn primary mark={2}>
                                    Pay
                                </Btn>
                                <Btn danger mark={3}>
                                    Fail
                                </Btn>
                            </span>,
                        ]}
                    />
                </Table>
                <div className="relative grid grid-cols-4 gap-1.5 rounded-lg border border-ln p-2">
                    <Box label="Amount paid ₹" value="15000" />
                    <Box label="UTR" value="IMPS601234567890" />
                    <Box label="Date" value="16 Nov" />
                    <Box label="Proof" value="receipt.pdf" />
                    <Mark n={4} className="absolute -top-2 -right-2" />
                </div>
            </div>
        </MockFrame>
    );
}

/** System › IP Management and the partner's Allowed IPs. */
export function IpMock() {
    return (
        <MockFrame url="/admin/ip-management">
            <div className="grid gap-2 sm:grid-cols-2">
                <div className="relative rounded-lg border border-ln p-2">
                    <div className="font-medium">Example Merchant</div>
                    <div className="text-[10px] text-er">
                        No allowed IPs: the API is closed
                    </div>
                    <div className="mt-1.5 text-[10px] text-tx3">
                        Called in the last 7 days
                    </div>
                    <div className="flex items-center justify-between font-mono text-[11px]">
                        203.0.113.25 <span className="text-er">12 refused</span>
                    </div>
                    <Mark n={1} className="absolute -top-2 -right-2" />
                </div>
                <div className="relative rounded-lg border border-ln p-2">
                    <div className="font-medium">
                        Partners › Edit › API &amp; security
                    </div>
                    <div className="mt-1 text-[10px] text-tx3">Allowed IPs</div>
                    <div className="rounded-md border border-ln bg-bg px-2 py-1 font-mono text-[11px]">
                        203.0.113.25
                        <br />
                        198.51.100.0/24
                    </div>
                    <div className="mt-1.5">
                        <Btn primary>Save</Btn>
                    </div>
                    <Mark n={2} className="absolute -top-2 -right-2" />
                </div>
            </div>
        </MockFrame>
    );
}

/** Finance › Settlement Dashboard. */
export function SettlementMock() {
    return (
        <MockFrame url="/admin/settlement-dashboard/partner">
            <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
                <Tile label="Deposits" value="₹12,50,000" />
                <Tile label="Commission" value="−₹75,000" />
                <Tile label="Payouts" value="−₹8,00,000" />
                <Tile label="Chargebacks" value="−₹5,000" />
                <Tile label="Current Balance" value="+₹3,70,000" tone="ok" />
                <Tile label="Net Balance" value="+₹70,000" tone="ok" />
            </div>
        </MockFrame>
    );
}

/** Reports: one report page. */
export function ReportMock() {
    return (
        <MockFrame url="/admin/reports/branch_deposits">
            <div className="flex flex-col gap-2">
                <div className="relative flex flex-wrap gap-1">
                    {[
                        'Today',
                        'Yesterday',
                        'This week',
                        'This month',
                        'Custom',
                    ].map((range, i) => (
                        <span
                            key={range}
                            className={cn(
                                'rounded-md border px-2 py-0.5 text-[11px]',
                                i === 0
                                    ? 'border-ac bg-acs text-ac'
                                    : 'border-ln text-tx2',
                            )}
                        >
                            {range}
                        </span>
                    ))}
                    <span className="flex-1" />
                    <Btn>Export CSV</Btn>
                    <Btn>Export Excel</Btn>
                    <Mark n={1} className="absolute -top-2 left-[-6px]" />
                </div>
                <Table>
                    <Row
                        head
                        cells={[
                            'Sr',
                            'Branch Name',
                            'Total Amount',
                            'Commission',
                            'Total Commission',
                        ]}
                    />
                    <Row
                        cells={[
                            '1',
                            'Branch A',
                            '₹12,50,000.00',
                            '4%',
                            '₹50,000.00',
                        ]}
                    />
                    <Row
                        cells={[
                            '2',
                            'Branch B',
                            '₹4,20,000.00',
                            '3.5%',
                            '₹14,700.00',
                        ]}
                    />
                </Table>
            </div>
        </MockFrame>
    );
}
