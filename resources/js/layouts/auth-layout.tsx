/**
 * Sign-in screens (design: magenta → plum brand panel on the left, form on
 * the right). One login for all portals; the account decides which portal opens.
 */
export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const points = [
        'Two-factor authentication for admin and branch users',
        'Every approval recorded in the audit log',
        'Partner and branch data kept strictly separate',
    ];

    return (
        <div
            data-portal="auth"
            className="flex min-h-svh bg-sf font-display text-tx"
        >
            <div className="relative hidden min-w-0 flex-1 flex-col justify-between gap-8 overflow-hidden bg-linear-135 from-[#e0287d] via-[#8e2479] to-[#3e1264] px-12 py-10 text-white/85 md:flex">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-40 -right-40 size-[520px] rounded-full bg-white/[.06] blur-3xl"
                />
                <div className="relative flex items-center gap-3">
                    <div className="grid size-10 place-items-center rounded-[10px] bg-white text-[17px] font-bold text-[#c2207a] shadow-lg shadow-black/10">
                        P
                    </div>
                    <span className="text-lg font-semibold tracking-tight text-white">
                        PayGate
                    </span>
                </div>

                <AuthIllustration />

                <div className="relative max-w-[480px]">
                    <div className="text-[34px] leading-[1.2] font-semibold tracking-[-.02em] text-pretty text-white">
                        Collections, payouts and reconciliation in one console.
                    </div>
                    <ul className="mt-7 flex flex-col gap-3.5 text-[14px]">
                        {points.map((point) => (
                            <li key={point} className="flex items-center gap-3">
                                <span className="grid size-[22px] place-items-center rounded-md bg-white/15 text-[11px] text-white">
                                    ✓
                                </span>
                                {point}
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="relative flex items-center gap-2 text-xs text-white/60">
                    <span className="size-[7px] rounded-full bg-[#10B981]" />©{' '}
                    {new Date().getFullYear()} PayGate
                </div>
            </div>
            <div className="relative flex min-w-[340px] flex-[0_1_620px] items-center justify-center overflow-hidden px-8 py-10 max-md:flex-1">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-48 -right-48 size-[480px] rounded-full bg-[#e0287d]/[.07] blur-3xl"
                />
                <div className="relative flex w-full max-w-[400px] flex-col gap-6">
                    <div className="flex items-center gap-2.5 md:hidden">
                        <div className="grid size-9 place-items-center rounded-[10px] bg-linear-135 from-[#e0287d] to-[#3e1264] text-[15px] font-bold text-white">
                            P
                        </div>
                        <span className="text-base font-semibold">PayGate</span>
                    </div>
                    {(title || description) && (
                        <div>
                            {title && (
                                <h1 className="text-[26px] font-semibold tracking-[-.015em]">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="mt-2 text-[14px] leading-relaxed text-tx2">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}
                    {children}
                </div>
            </div>
        </div>
    );
}

/** Dashboard + QR card + "deposit approved" toast, drawn with plain divs. */
function AuthIllustration() {
    const bars = [30, 52, 40, 68, 56, 82, 62, 74];
    // 7×7 QR-ish grid: 1 = magenta dot (corners are drawn separately).
    const qr = [
        '0010100',
        '0001000',
        '1010010',
        '0101101',
        '0010100',
        '0000010',
        '0001010',
    ];

    return (
        <div
            aria-hidden
            className="relative hidden h-[300px] w-[440px] shrink-0 xl:block [@media(max-height:760px)]:hidden"
        >
            {/* Dashboard window */}
            <div className="absolute top-0 left-6 h-[230px] w-[330px] rounded-2xl border border-white/20 bg-white/10 backdrop-blur-sm">
                <div className="flex items-center gap-1.5 rounded-t-2xl bg-white/10 px-4 py-3">
                    <span className="size-2 rounded-full bg-white/60" />
                    <span className="size-2 rounded-full bg-white/45" />
                    <span className="size-2 rounded-full bg-white/30" />
                </div>
                <div className="grid grid-cols-3 gap-3 px-4 pt-4">
                    {[0, 1, 2].map((i) => (
                        <div
                            key={i}
                            className="flex flex-col gap-1.5 rounded-lg bg-white/10 p-3"
                        >
                            <span className="h-1.5 w-8 rounded-full bg-white/50" />
                            <span className="h-2 w-12 rounded-full bg-white/80" />
                        </div>
                    ))}
                </div>
                <div className="flex h-[100px] items-end gap-3.5 px-6 pt-4">
                    {bars.map((h, i) => (
                        <span
                            key={i}
                            className="w-4 rounded-t-[3px] bg-white/30"
                            style={{ height: `${h}%` }}
                        />
                    ))}
                </div>
            </div>

            {/* QR card */}
            <div className="absolute top-[92px] left-[300px] w-[124px] rounded-xl bg-white p-3 shadow-2xl shadow-[#2a0b45]/40">
                <div className="relative grid aspect-square grid-cols-7 gap-[3px] rounded-md bg-[#fdf0f6] p-2">
                    {qr.flatMap((row, r) =>
                        [...row].map((cell, c) => (
                            <span
                                key={`${r}-${c}`}
                                className={
                                    cell === '1'
                                        ? 'rounded-[1px] bg-[#d6287f]'
                                        : ''
                                }
                            />
                        )),
                    )}
                    {['top-2 left-2', 'top-2 right-2', 'bottom-2 left-2'].map(
                        (pos) => (
                            <span
                                key={pos}
                                className={`absolute ${pos} size-3.5 rounded-[3px] border-[3px] border-[#3e1264] bg-white`}
                            />
                        ),
                    )}
                </div>
                <div className="mt-2.5 h-1.5 rounded-full bg-[#5b2a86]" />
                <div className="mx-4 mt-1.5 h-1 rounded-full bg-[#5b2a86]/35" />
            </div>

            {/* Deposit approved toast */}
            <div className="absolute bottom-0 left-0 flex w-[200px] items-center gap-2.5 rounded-xl bg-white px-3.5 py-3 shadow-xl shadow-[#2a0b45]/30">
                <span className="grid size-6 shrink-0 place-items-center rounded-full bg-[#10b981] text-[11px] font-bold text-white">
                    ✓
                </span>
                <div className="min-w-0 leading-tight">
                    <div className="text-[12px] font-semibold text-[#1e1b2e]">
                        Deposit approved
                    </div>
                    <div className="mt-0.5 text-[10px] text-[#6b6580]">
                        ₹12,500 · UTR matched
                    </div>
                </div>
            </div>
        </div>
    );
}
