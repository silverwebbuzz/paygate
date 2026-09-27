/**
 * Sign-in screens (design: dark brand panel on the left, form on the right).
 * One login for all portals; the account decides which portal opens.
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
        <div data-portal="admin" className="flex min-h-svh bg-sf text-tx">
            <div className="hidden min-w-0 flex-1 flex-col justify-between gap-8 bg-nav px-12 py-10 text-[#C8D1DF] md:flex">
                <div className="flex items-center gap-2.5">
                    <div className="grid size-8 place-items-center rounded-[9px] bg-ac text-[15px] font-bold text-white">
                        P
                    </div>
                    <span className="text-base font-semibold tracking-tight text-white">
                        PayGate
                    </span>
                </div>
                <div className="max-w-[440px]">
                    <div className="text-[30px] leading-tight font-semibold tracking-[-.02em] text-pretty text-white">
                        Collections, payouts and reconciliation in one console.
                    </div>
                    <ul className="mt-7 flex flex-col gap-3 text-[13.5px]">
                        {points.map((point) => (
                            <li
                                key={point}
                                className="flex items-center gap-2.5"
                            >
                                <span className="grid size-[22px] place-items-center rounded-md bg-white/10 text-[11px] text-white">
                                    ✓
                                </span>
                                {point}
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="flex items-center gap-2 text-xs text-[#8391A8]">
                    <span className="size-[7px] rounded-full bg-[#10B981]" />©{' '}
                    {new Date().getFullYear()} PayGate
                </div>
            </div>
            <div className="flex min-w-[340px] flex-[0_1_520px] items-center justify-center px-8 py-10 max-md:flex-1">
                <div className="flex w-full max-w-[380px] flex-col gap-5">
                    <div className="flex items-center gap-2.5 md:hidden">
                        <div className="grid size-8 place-items-center rounded-[9px] bg-ac text-[15px] font-bold text-white">
                            P
                        </div>
                        <span className="text-base font-semibold">PayGate</span>
                    </div>
                    {(title || description) && (
                        <div>
                            {title && (
                                <h1 className="text-[22px] font-semibold tracking-[-.015em]">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="mt-1.5 text-[13.5px] text-tx2">
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
