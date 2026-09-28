import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Clock, Copy, ImageUp, ShieldCheck, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime } from '@/lib/dates';
import { formatPaise } from '@/lib/money';
import { cn } from '@/lib/utils';
import checkout from '@/routes/pay/checkout';

type Method = 'upi' | 'qr' | 'bank_transfer';

type Props = {
    /** Global Settings: support contact and published pages (G-48). */
    support: { email: string | null; phone: string | null };
    pages: { title: string; url: string }[];
    token: string;
    partner: { name: string; initials: string };
    payin: {
        reference: string;
        order_id: string;
        amount: number;
        status: string;
        method: Method | null;
        utr: string | null;
        created_at: string | null;
        expires_at: string | null;
        submitted_at: string | null;
        completed_at: string | null;
    };
    server_time: string;
    methods: Method[];
    account: {
        holder: string;
        bank_name: string | null;
        account_number: string | null;
        ifsc: string | null;
        upi_id: string | null;
        upi_name: string;
        supports: Method[];
        qr_svg: string | null;
        apps: { app: string; label: string; url: string }[];
    } | null;
    return_url: string | null;
    proof: { max_kb: number; types: string[] };
};

const METHOD_INFO: Record<
    Method,
    { label: string; icon: string; sub: string }
> = {
    upi: { label: 'UPI', icon: 'UPI', sub: 'Pay with any UPI app · instant' },
    bank_transfer: {
        label: 'Bank transfer',
        icon: '₹',
        sub: 'IMPS / NEFT / RTGS · 2–10 min',
    },
    qr: { label: 'QR code', icon: 'QR', sub: 'Scan with any UPI app' },
};

/**
 * The customer's payment page (design: checkout). States: choose a method →
 * pay (UPI / bank / QR) and submit UTR or screenshot → verifying → success;
 * or failed / expired / unavailable.
 */
export default function Checkout(props: Props) {
    const { partner, payin, account } = props;
    const status = payin.status;
    const open = status === 'created' || status === 'awaiting_payment';
    const waiting = [
        'payment_submitted',
        'payment_detected',
        'under_review',
    ].includes(status);
    const [choosing, setChoosing] = useState(!account);

    useEffect(() => setChoosing(!account), [account]);

    // While the branch checks the payment, look for the result every 5 s.
    useEffect(() => {
        if (!waiting) return;
        const timer = setInterval(
            () => router.reload({ only: ['payin', 'account'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [waiting]);

    return (
        <div
            data-portal="partner"
            className="flex min-h-screen justify-center bg-[#f3f4f7] sm:py-8"
        >
            <Head title={`Pay ${partner.name}`} />
            <div className="flex w-full max-w-[420px] flex-col overflow-hidden bg-white text-[14px] text-[#0F172A] sm:rounded-[28px] sm:border sm:border-[#E4E7EC] sm:shadow-[0_30px_80px_rgba(15,23,42,.14)]">
                <header className="flex items-center gap-2.5 border-b border-[#EEF0F3] px-5 py-3.5">
                    <div className="grid size-9 place-items-center rounded-[10px] bg-ac text-[13px] font-bold text-white">
                        {partner.initials}
                    </div>
                    <div className="flex-1">
                        <div className="font-semibold">{partner.name}</div>
                        <div className="flex items-center gap-1.5 text-xs text-[#64748B]">
                            <ShieldCheck className="size-3.5 text-[#047857]" />{' '}
                            Secure payment
                        </div>
                    </div>
                </header>

                {(open || waiting) && <AmountCard {...props} ticking={open} />}

                <main className="flex flex-1 flex-col gap-3 px-5 py-4">
                    {open && choosing && (
                        <ChooseMethod
                            {...props}
                            onCancel={
                                account ? () => setChoosing(false) : undefined
                            }
                        />
                    )}
                    {open && !choosing && account && (
                        <PayView
                            {...props}
                            onChooseAnother={() => setChoosing(true)}
                        />
                    )}
                    {waiting && (
                        <Result
                            tone="pending"
                            title="Verifying your payment"
                            body="The receiving bank account is being checked for your transfer. This usually takes a few minutes — you can keep this page open, it updates by itself."
                            rows={[
                                ['Amount', formatPaise(payin.amount)],
                                ['UTR', payin.utr ?? 'Screenshot sent'],
                                ['Order ID', payin.order_id],
                            ]}
                        />
                    )}
                    {status === 'success' && (
                        <Result
                            tone="success"
                            title="Payment successful"
                            body={`${formatPaise(payin.amount)} has been received by ${partner.name}.`}
                            rows={[
                                ['Amount paid', formatPaise(payin.amount)],
                                ['Reference', payin.utr ?? payin.reference],
                                ['Paid on', formatDateTime(payin.completed_at)],
                            ]}
                            action={
                                props.return_url
                                    ? {
                                          label: `Return to ${partner.name}`,
                                          href: props.return_url,
                                      }
                                    : undefined
                            }
                        />
                    )}
                    {status === 'rejected' && (
                        <Result
                            tone="failed"
                            title="Payment could not be verified"
                            body="The payment could not be matched. If money left your account, contact the merchant with the reference below."
                            rows={[
                                ['Amount', formatPaise(payin.amount)],
                                ['Order ID', payin.order_id],
                                ['Reference', payin.reference],
                            ]}
                            action={
                                props.return_url
                                    ? {
                                          label: `Return to ${partner.name}`,
                                          href: props.return_url,
                                      }
                                    : undefined
                            }
                        />
                    )}
                    {(status === 'expired' || status === 'cancelled') && (
                        <Result
                            tone="expired"
                            title={
                                status === 'expired'
                                    ? 'Payment session expired'
                                    : 'Payment cancelled'
                            }
                            body="Do not transfer to the old account details. Start a new payment from the merchant’s site instead."
                            rows={[
                                ['Amount', formatPaise(payin.amount)],
                                ['Order ID', payin.order_id],
                                [
                                    status === 'expired'
                                        ? 'Expired at'
                                        : 'Cancelled at',
                                    formatDateTime(
                                        payin.completed_at ?? payin.expires_at,
                                    ),
                                ],
                            ]}
                            action={
                                props.return_url
                                    ? {
                                          label: `Back to ${partner.name}`,
                                          href: props.return_url,
                                      }
                                    : undefined
                            }
                        />
                    )}
                </main>

                <footer className="flex flex-col gap-1.5 border-t border-[#EEF0F3] px-5 py-3 text-[11.5px] text-[#64748B]">
                    <div className="flex justify-between">
                        <span className="font-mono">{payin.reference}</span>
                        <span>
                            Secured by <b className="text-[#0F172A]">PayGate</b>
                        </span>
                    </div>
                    {(props.support.email ||
                        props.support.phone ||
                        props.pages.length > 0) && (
                        <div className="flex flex-wrap justify-between gap-x-3 gap-y-1">
                            <span>
                                {(props.support.email || props.support.phone) &&
                                    'Help: '}
                                {props.support.email && (
                                    <a
                                        href={`mailto:${props.support.email}`}
                                        className="underline"
                                    >
                                        {props.support.email}
                                    </a>
                                )}
                                {props.support.email &&
                                    props.support.phone &&
                                    ' · '}
                                {props.support.phone && (
                                    <a
                                        href={`tel:${props.support.phone}`}
                                        className="underline"
                                    >
                                        {props.support.phone}
                                    </a>
                                )}
                            </span>
                            <span className="flex gap-2">
                                {props.pages.map((page) => (
                                    <a
                                        key={page.url}
                                        href={page.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline"
                                    >
                                        {page.title}
                                    </a>
                                ))}
                            </span>
                        </div>
                    )}
                </footer>
            </div>
        </div>
    );
}

function AmountCard({
    payin,
    server_time,
    ticking,
}: Props & { ticking: boolean }) {
    const offset = useMemo(
        () => new Date(server_time).getTime() - Date.now(),
        [server_time],
    );
    const deadline = payin.expires_at
        ? new Date(payin.expires_at).getTime()
        : null;
    const [now, setNow] = useState(() => Date.now() + offset);

    useEffect(() => {
        if (!ticking || deadline === null) return;
        const timer = setInterval(() => {
            const current = Date.now() + offset;
            setNow(current);

            // Time is up: let the server expire it and show the expired state.
            if (current >= deadline) {
                clearInterval(timer);
                router.reload();
            }
        }, 1000);

        return () => clearInterval(timer);
    }, [ticking, deadline, offset]);

    const left =
        deadline === null
            ? null
            : Math.max(0, Math.floor((deadline - now) / 1000));
    const total =
        deadline === null || payin.created_at === null
            ? 1
            : Math.max(
                  1,
                  (deadline - new Date(payin.created_at).getTime()) / 1000,
              );
    const urgent = left !== null && left < 120;

    return (
        <div className="mx-5 mt-4 rounded-[14px] border border-[#EEF0F3] bg-[#F8FAFC] p-4">
            <div className="text-xs text-[#64748B]">Amount to pay</div>
            <div className="mt-0.5 text-[30px] font-bold tracking-[-.02em]">
                {formatPaise(payin.amount)}
            </div>
            <div className="mt-2 flex items-center justify-between text-xs text-[#475569]">
                <span>
                    Order <span className="font-mono">{payin.order_id}</span>
                </span>
                {ticking && left !== null && (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 font-semibold',
                            urgent ? 'text-[#B91C1C]' : 'text-ac',
                        )}
                    >
                        <Clock className="size-3.5" />
                        {Math.floor(left / 60)}:
                        {String(left % 60).padStart(2, '0')}
                    </span>
                )}
            </div>
            {ticking && left !== null && (
                <div className="mt-2 h-1 overflow-hidden rounded-full bg-[#E4E7EC]">
                    <div
                        className={cn(
                            'h-full',
                            urgent ? 'bg-[#B91C1C]' : 'bg-ac',
                        )}
                        style={{
                            width: `${Math.min(100, (left / total) * 100)}%`,
                        }}
                    />
                </div>
            )}
        </div>
    );
}

function ChooseMethod({
    token,
    methods,
    payin,
    onCancel,
}: Props & { onCancel?: () => void }) {
    const form = useForm({ method: '' as string });
    const error = (usePage().props.errors as Record<string, string>).method;

    const choose = (method: Method) => {
        form.transform(() => ({ method }));
        form.post(checkout.method(token).url, { preserveScroll: true });
    };

    return (
        <>
            <div className="text-[15px] font-semibold">Choose how to pay</div>
            {error && (
                <div className="flex items-start gap-2 rounded-xl bg-[#FFFBEB] px-3 py-2.5 text-[13px] text-[#B45309]">
                    <Clock className="mt-0.5 size-4 flex-none" /> {error}
                </div>
            )}
            {methods.map((method) => (
                <button
                    key={method}
                    type="button"
                    disabled={form.processing}
                    onClick={() => choose(method)}
                    className={cn(
                        'flex items-center gap-3 rounded-[14px] border px-4 py-3.5 text-left hover:border-ac disabled:opacity-60',
                        payin.method === method
                            ? 'border-ac bg-acs'
                            : 'border-[#E4E7EC]',
                    )}
                >
                    <span className="grid size-10 place-items-center rounded-[10px] bg-acs text-xs font-bold text-act">
                        {METHOD_INFO[method].icon}
                    </span>
                    <span className="flex-1">
                        <span className="block font-semibold">
                            {METHOD_INFO[method].label}
                        </span>
                        <span className="block text-xs text-[#64748B]">
                            {METHOD_INFO[method].sub}
                        </span>
                    </span>
                    <span className="text-[#94A3B8]">›</span>
                </button>
            ))}
            {methods.length === 0 && (
                <p className="text-[13px] text-[#64748B]">
                    No payment method is available. Please contact the merchant.
                </p>
            )}
            {onCancel && (
                <button
                    type="button"
                    onClick={onCancel}
                    className="text-[13px] font-medium text-[#475569]"
                >
                    ← Back to payment details
                </button>
            )}
        </>
    );
}

function PayView({
    token,
    payin,
    account,
    methods,
    proof,
    onChooseAnother,
}: Props & { onChooseAnother: () => void }) {
    const form = useForm<{ utr: string; photo: File | null }>({
        utr: '',
        photo: null,
    });
    const switcher = useForm({ method: '' as string });
    const tabs = methods.filter((method) => account!.supports.includes(method));
    const current = (payin.method ?? tabs[0]) as Method;

    const switchTo = (method: Method) => {
        if (method === current) return;
        switcher.transform(() => ({ method }));
        switcher.post(checkout.method(token).url, { preserveScroll: true });
    };

    return (
        <>
            {tabs.length > 1 && (
                <div className="grid grid-flow-col gap-1 rounded-xl bg-[#F1F5F9] p-1">
                    {tabs.map((method) => (
                        <button
                            key={method}
                            type="button"
                            onClick={() => switchTo(method)}
                            className={cn(
                                'h-8 rounded-lg text-[13px]',
                                method === current
                                    ? 'bg-white font-semibold shadow-[0_1px_2px_rgba(15,23,42,.1)]'
                                    : 'font-medium text-[#475569]',
                            )}
                        >
                            {METHOD_INFO[method].label}
                        </button>
                    ))}
                </div>
            )}

            {current === 'bank_transfer' && account!.account_number && (
                <>
                    <div className="overflow-hidden rounded-[14px] border border-[#E4E7EC]">
                        <CopyRow
                            label="Bank name"
                            value={account!.bank_name ?? ''}
                            copyable={false}
                        />
                        <CopyRow
                            label="Account holder"
                            value={account!.holder}
                            copyable={false}
                        />
                        <CopyRow
                            label="Account number"
                            value={account!.account_number}
                            mono
                        />
                        <CopyRow
                            label="IFSC"
                            value={account!.ifsc ?? ''}
                            mono
                        />
                        <CopyRow
                            label="Amount"
                            value={formatPaise(payin.amount)}
                            copyValue={(payin.amount / 100).toFixed(2)}
                        />
                    </div>
                    <Note>
                        Transfer the <b>exact amount</b> by IMPS / NEFT from
                        your bank app. These details are for this payment only —
                        don’t save them for later.
                    </Note>
                </>
            )}

            {current === 'upi' && account!.upi_id && (
                <>
                    <div className="overflow-hidden rounded-[14px] border border-[#E4E7EC]">
                        <CopyRow
                            label="Pay to UPI ID"
                            value={account!.upi_id}
                            mono
                            big
                        />
                        <CopyRow
                            label="Name"
                            value={account!.upi_name}
                            copyable={false}
                        />
                        <CopyRow
                            label="Amount"
                            value={formatPaise(payin.amount)}
                            copyValue={(payin.amount / 100).toFixed(2)}
                        />
                    </div>
                    {account!.apps.length > 0 && (
                        <>
                            <div className="text-xs font-medium text-[#64748B]">
                                Open in app
                            </div>
                            <div className="grid grid-cols-4 gap-2">
                                {account!.apps.map((app) => (
                                    <a
                                        key={app.app}
                                        href={app.url}
                                        className="flex flex-col items-center gap-1 rounded-xl border border-[#E4E7EC] px-1 py-2.5 text-[11.5px] font-medium hover:border-ac"
                                    >
                                        <span className="grid size-8 place-items-center rounded-lg bg-acs text-[11px] font-bold text-act">
                                            {app.label.slice(0, 2)}
                                        </span>
                                        {app.label}
                                    </a>
                                ))}
                            </div>
                        </>
                    )}
                    <Note>
                        Pay the exact amount. Your UPI app will show a 12-digit
                        UTR / reference number after paying.
                    </Note>
                </>
            )}

            {current === 'qr' && account!.qr_svg && (
                <div className="flex flex-col items-center gap-2 rounded-[14px] border border-[#E4E7EC] p-4">
                    <div
                        className="size-[220px] [&_svg]:size-full"
                        dangerouslySetInnerHTML={{ __html: account!.qr_svg }}
                    />
                    <div className="text-center text-xs text-[#64748B]">
                        Scan with any UPI app. The amount is filled in for you.
                    </div>
                </div>
            )}

            <form
                className="flex flex-col gap-3 border-t border-[#EEF0F3] pt-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(checkout.proof(token).url, {
                        forceFormData: true,
                        preserveScroll: true,
                    });
                }}
            >
                <label className="flex flex-col gap-1.5 text-[13px] font-medium">
                    <span>
                        UTR / reference number{' '}
                        <span className="font-normal text-[#64748B]">
                            (from your bank or UPI app)
                        </span>
                    </span>
                    <input
                        inputMode="text"
                        autoComplete="off"
                        placeholder="e.g. 626812820491"
                        value={form.data.utr}
                        onChange={(event) =>
                            form.setData('utr', event.target.value)
                        }
                        className="h-11 rounded-xl border border-[#E4E7EC] px-3 font-mono text-[15px] outline-none focus:border-ac focus:ring-[3px] focus:ring-acs"
                    />
                </label>
                <label className="flex cursor-pointer items-center gap-2.5 rounded-xl border border-dashed border-[#CBD5E1] px-3 py-2.5 text-[13px] text-[#475569] hover:border-ac">
                    <ImageUp className="size-4 flex-none" />
                    <span className="flex-1 truncate">
                        {form.data.photo
                            ? form.data.photo.name
                            : 'Or upload a screenshot of the payment'}
                    </span>
                    {form.data.photo && (
                        <button
                            type="button"
                            onClick={(event) => {
                                event.preventDefault();
                                form.setData('photo', null);
                            }}
                        >
                            <X className="size-4" />
                        </button>
                    )}
                    <input
                        type="file"
                        className="hidden"
                        accept={proof.types.map((type) => `.${type}`).join(',')}
                        onChange={(event) =>
                            form.setData(
                                'photo',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </label>
                {(form.errors.utr || form.errors.photo) && (
                    <p className="text-[12.5px] text-[#B91C1C]">
                        {form.errors.utr ?? form.errors.photo}
                    </p>
                )}
                <button
                    type="submit"
                    disabled={
                        form.processing ||
                        (form.data.utr.trim() === '' &&
                            form.data.photo === null)
                    }
                    className="h-12 rounded-xl bg-ac text-[15px] font-semibold text-white disabled:opacity-50"
                >
                    I’ve made the payment
                </button>
            </form>
            <button
                type="button"
                onClick={onChooseAnother}
                className="text-[13px] font-medium text-[#475569]"
            >
                ← Choose another method
            </button>
        </>
    );
}

function CopyRow({
    label,
    value,
    copyValue,
    mono,
    big,
    copyable = true,
}: {
    label: string;
    value: string;
    copyValue?: string;
    mono?: boolean;
    big?: boolean;
    copyable?: boolean;
}) {
    const [copied, copy] = useClipboard();
    const target = copyValue ?? value;

    return (
        <div className="flex items-center gap-3 border-b border-[#EEF0F3] px-3.5 py-2.5 last:border-b-0">
            <div className="min-w-0 flex-1">
                <div className="text-[11.5px] text-[#64748B]">{label}</div>
                <div
                    className={cn(
                        'break-all',
                        mono && 'font-mono',
                        big
                            ? 'text-[15px] font-semibold'
                            : 'text-[14px] font-medium',
                    )}
                >
                    {value}
                </div>
            </div>
            {copyable && (
                <button
                    type="button"
                    onClick={() => copy(target)}
                    className="inline-flex h-8 items-center gap-1 rounded-lg border border-[#E4E7EC] px-2.5 text-xs font-medium"
                >
                    {copied === target ? (
                        <Check className="size-3.5 text-[#047857]" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copied === target ? 'Copied' : 'Copy'}
                </button>
            )}
        </div>
    );
}

function Note({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl bg-[#F8FAFC] px-3 py-2.5 text-[12.5px] leading-relaxed text-[#475569]">
            {children}
        </div>
    );
}

const TONES = {
    pending: {
        bg: 'bg-[#F5F3FF]',
        fg: 'text-[#6D28D9]',
        ring: 'ring-[#DDD6FE]',
        icon: <Clock className="size-7" />,
    },
    success: {
        bg: 'bg-[#ECFDF5]',
        fg: 'text-[#047857]',
        ring: 'ring-[#A7F3D0]',
        icon: <Check className="size-7" />,
    },
    failed: {
        bg: 'bg-[#FEF2F2]',
        fg: 'text-[#B91C1C]',
        ring: 'ring-[#FECACA]',
        icon: <X className="size-7" />,
    },
    expired: {
        bg: 'bg-[#FFFBEB]',
        fg: 'text-[#B45309]',
        ring: 'ring-[#FDE68A]',
        icon: <Clock className="size-7" />,
    },
};

function Result({
    tone,
    title,
    body,
    rows,
    action,
}: {
    tone: keyof typeof TONES;
    title: string;
    body: string;
    rows: [string, string][];
    action?: { label: string; href: string };
}) {
    const style = TONES[tone];

    return (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
            <div
                className={cn(
                    'grid size-16 place-items-center rounded-full ring-[3px]',
                    style.bg,
                    style.fg,
                    style.ring,
                )}
            >
                {style.icon}
            </div>
            <div className="text-lg font-semibold">{title}</div>
            <p className="text-[13px] leading-relaxed text-[#475569]">{body}</p>
            <div className="mt-1 w-full overflow-hidden rounded-[14px] border border-[#EEF0F3] text-left">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex justify-between gap-3 border-b border-[#EEF0F3] px-3.5 py-2.5 text-[13px] last:border-b-0"
                    >
                        <span className="text-[#64748B]">{label}</span>
                        <span className="font-medium break-all">{value}</span>
                    </div>
                ))}
            </div>
            {action && (
                <a
                    href={action.href}
                    className="mt-1 grid h-12 w-full place-items-center rounded-xl bg-ac text-[15px] font-semibold text-white"
                >
                    {action.label}
                </a>
            )}
        </div>
    );
}
