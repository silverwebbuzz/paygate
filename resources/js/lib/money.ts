/**
 * Amounts travel as integer paise (₹1 = 100). Format for display in Indian
 * grouping: 1234567 paise → "₹12,345.67".
 */
export function formatPaise(
    paise: number | null | undefined,
    decimals = 2,
): string {
    if (paise === null || paise === undefined) {
        return '—';
    }

    return (
        '₹' +
        (paise / 100).toLocaleString('en-IN', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        })
    );
}

/** Limits: null means unlimited (the UI accepts −1 as "unlimited" on input). */
export function formatLimit(paise: number | null | undefined): string {
    return paise === null || paise === undefined
        ? 'Unlimited'
        : formatPaise(paise, 0);
}
