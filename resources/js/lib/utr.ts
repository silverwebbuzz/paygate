/**
 * What a UTR box keeps of what was typed or pasted (client request
 * 2026-11-18): numbers for UPI / IMPS ("UTR: 6268-1282-0491" →
 * "626812820491"); with `bankReference`, also the letters of a NEFT / RTGS
 * reference, in capitals ("hdfcn5202 6092812" → "HDFCN52026092812"). The
 * server checks the exact format.
 */
export function cleanUtr(value: string, bankReference = true): string {
    return bankReference
        ? value
              .toUpperCase()
              .replace(/[^A-Z0-9]/g, '')
              .slice(0, 22)
        : value.replace(/\D/g, '').slice(0, 22);
}
