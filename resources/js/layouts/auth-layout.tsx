/**
 * Sign-in screens: one centred card on the page background. Left half is the
 * magenta → plum brand panel (logo + illustration), right half is the form.
 * One login for all portals; the account decides which portal opens.
 */
export default function AuthLayout({
    title = "",
    description = "",
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <div
            data-portal="auth"
            className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-bg px-4 py-10 font-display text-tx sm:px-8"
        >
            <div
                aria-hidden
                className="pointer-events-none absolute -top-56 -left-56 size-[560px] rounded-full bg-[#e0287d]/[.08] blur-3xl"
            />
            <div
                aria-hidden
                className="pointer-events-none absolute -right-56 -bottom-56 size-[560px] rounded-full bg-[#3e1264]/[.08] blur-3xl"
            />

            <div className="relative flex w-full max-w-[1040px] overflow-hidden rounded-[20px] border border-border bg-sf shadow-2xl shadow-[#3e1264]/10 lg:min-h-[560px] lg:p-3">
                {/* Brand panel */}
                <div className="relative hidden w-1/2 shrink-0 flex-col items-center justify-center gap-12 overflow-hidden rounded-2xl bg-linear-135 from-[#e0287d] via-[#8e2479] to-[#3e1264] px-8 py-12 lg:flex">
                    <div
                        aria-hidden
                        className="pointer-events-none absolute -top-40 -right-40 size-[420px] rounded-full bg-white/[.06] blur-3xl"
                    />
                    <div className="relative flex items-center gap-3">
                        <div className="grid size-11 place-items-center rounded-[11px] bg-white text-[19px] font-bold text-[#c2207a] shadow-lg shadow-black/10">
                            P
                        </div>
                        <span className="text-[22px] font-semibold tracking-tight text-white">
                            PayGate
                        </span>
                    </div>

                    <AuthIllustration />
                </div>

                {/* Form */}
                <div className="flex min-w-0 flex-1 items-center justify-center px-6 py-10 sm:px-12">
                    <div className="flex w-full max-w-[420px] flex-col gap-6">
                        <div className="flex items-center gap-2.5 lg:hidden">
                            <div className="grid size-9 place-items-center rounded-[10px] bg-linear-135 from-[#e0287d] to-[#3e1264] text-[15px] font-bold text-white">
                                P
                            </div>
                            <span className="text-base font-semibold">
                                PayGate
                            </span>
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

            <div className="relative mt-6 flex items-center gap-2 text-xs text-tx3">
                <span className="size-[7px] rounded-full bg-[#10B981]" />©{" "}
                {new Date().getFullYear()} PayGate
            </div>
        </div>
    );
}

/** Dashboard + QR card + "deposit approved" toast, drawn with plain divs. */
function AuthIllustration() {
    const bars = [30, 52, 40, 68, 56, 82, 62, 74];
    // 7×7 QR-ish grid: 1 = magenta dot (corners are drawn separately).
    const qr = [
        "0010100",
        "0001000",
        "1010010",
        "0101101",
        "0010100",
        "0000010",
        "0001010",
    ];

    return (
        <div
            aria-hidden
            className="relative h-[300px] w-[440px] shrink-0 max-xl:scale-90"
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
                                    cell === "1"
                                        ? "rounded-[1px] bg-[#d6287f]"
                                        : ""
                                }
                            />
                        )),
                    )}
                    {["top-2 left-2", "top-2 right-2", "bottom-2 left-2"].map(
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
