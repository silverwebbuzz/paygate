import { useCallback, useState } from 'react';

export type Density = 'compact' | 'comfortable';

const KEY = 'pg-density';

function stored(): Density {
    try {
        return localStorage.getItem(KEY) === 'comfortable'
            ? 'comfortable'
            : 'compact';
    } catch {
        return 'compact';
    }
}

/** Table row density (design: compact 9px / comfortable 13px row padding). */
export function useDensity(): [Density, () => void] {
    const [density, setDensity] = useState<Density>(() =>
        typeof window === 'undefined' ? 'compact' : stored(),
    );

    const toggle = useCallback(() => {
        setDensity((current) => {
            const next: Density =
                current === 'compact' ? 'comfortable' : 'compact';
            try {
                localStorage.setItem(KEY, next);
            } catch {
                // private mode: keep the in-memory value
            }
            return next;
        });
    }, []);

    return [density, toggle];
}
