/**
 * Sign-in screens: one centred card on the page background. The left half is
 * an inset brand panel (logo + illustration), the right half is the form.
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
    return (
        <div
            data-portal="auth"
            className="flex min-h-svh items-center justify-center bg-bg px-4 py-10 font-display text-tx sm:px-8"
        >
            <div className="flex w-full max-w-[1000px] rounded-2xl border border-border bg-sf p-3 shadow-2xl shadow-[#3e1264]/10 sm:p-5">
                {/* Brand panel */}
                <div className="hidden w-1/2 shrink-0 flex-col items-center justify-center gap-10 rounded-xl bg-linear-135 from-[#e0287d]/[.07] to-[#3e1264]/[.09] px-8 py-12 lg:flex">
                    <Logo size="lg" />
                    <img
                        src="/images/auth/login.webp"
                        alt=""
                        width={762}
                        height={528}
                        className="w-full max-w-[340px]"
                    />
                </div>

                {/* Form */}
                <div className="flex min-w-0 flex-1 items-center justify-center px-4 py-8 sm:px-10">
                    <div className="flex w-full max-w-[420px] flex-col gap-6">
                        <div className="lg:hidden">
                            <Logo size="sm" />
                        </div>
                        {(title || description) && (
                            <div>
                                {title && (
                                    <h1 className="text-[26px] font-semibold tracking-[-.015em]">
                                        {title}
                                    </h1>
                                )}
                                {description && (
                                    <p className="mt-1.5 text-[14px] leading-relaxed text-tx2">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}

function Logo({ size }: { size: 'sm' | 'lg' }) {
    const lg = size === 'lg';

    return (
        <div className={`flex items-center ${lg ? 'gap-3' : 'gap-2.5'}`}>
            <div
                className={`grid place-items-center bg-linear-135 from-[#e0287d] to-[#3e1264] font-bold text-white shadow-lg shadow-[#e0287d]/25 ${lg ? 'size-11 rounded-[11px] text-[19px]' : 'size-9 rounded-[10px] text-[15px]'}`}
            >
                P
            </div>
            <span
                className={`bg-linear-to-r from-[#e0287d] to-[#3e1264] bg-clip-text font-semibold tracking-tight text-transparent dark:from-[#f0509b] dark:to-[#b07ae0] ${lg ? 'text-[24px]' : 'text-base'}`}
            >
                PayGate
            </span>
        </div>
    );
}
