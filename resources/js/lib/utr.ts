/**
 * A UTR has numbers only (client request 2026-11-18): keep the digits of
 * what was typed or pasted ("UTR: 6268-1282-0491" → "626812820491").
 */
export function utrDigits(value: string): string {
    return value.replace(/\D/g, '').slice(0, 22);
}
