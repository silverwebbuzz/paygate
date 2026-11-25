import { useCallback, useEffect, useState } from 'react';

/**
 * The request sound (client request 2026-11-25): a beep on every portal page
 * when a new deposit or withdrawal request comes in, a different one for
 * each. Deposit: two quick rising beeps. Withdrawal: two lower, longer beeps.
 *
 * Polls GET /notifications/pulse (RequestPulseController) for the newest
 * request the person can see. The first answer is only remembered, so
 * opening a page never beeps. With several tabs open only one beeps: the
 * last timestamp beeped for is kept in localStorage.
 *
 * Browsers play sound only after the person has clicked or typed on the
 * page once; until then a beep is skipped silently.
 */
const POLL_MS = 10000;
const MUTE_KEY = 'pg-request-sound';
const HEARD_KEY = 'pg-request-sound-heard';

type Kind = 'deposit' | 'withdrawal';
type Pulse = Record<Kind, string | null>;

// [frequency Hz, start s, length s]
const TONES: Record<Kind, [number, number, number][]> = {
    deposit: [
        [880, 0, 0.12],
        [1320, 0.16, 0.18],
    ],
    withdrawal: [
        [440, 0, 0.28],
        [440, 0.38, 0.28],
    ],
};

let context: AudioContext | null = null;

function audio(): AudioContext | null {
    if (typeof window === 'undefined' || !('AudioContext' in window)) {
        return null;
    }

    context ??= new AudioContext();

    return context;
}

export function playRequestSound(kind: Kind) {
    const ctx = audio();

    if (!ctx || ctx.state !== 'running') return;

    const now = ctx.currentTime;

    for (const [frequency, start, length] of TONES[kind]) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = kind === 'deposit' ? 'sine' : 'square';
        osc.frequency.value = frequency;
        // Short fade in and out, so the beep doesn't click.
        const peak = kind === 'deposit' ? 0.35 : 0.12;
        gain.gain.setValueAtTime(0, now + start);
        gain.gain.linearRampToValueAtTime(peak, now + start + 0.01);
        gain.gain.setValueAtTime(peak, now + start + length - 0.03);
        gain.gain.linearRampToValueAtTime(0, now + start + length);
        osc.connect(gain).connect(ctx.destination);
        osc.start(now + start);
        osc.stop(now + start + length + 0.02);
    }
}

function heard(): Partial<Pulse> {
    try {
        return JSON.parse(localStorage.getItem(HEARD_KEY) ?? '{}');
    } catch {
        return {};
    }
}

export function useRequestSound() {
    const [muted, setMuted] = useState(
        () =>
            typeof window !== 'undefined' &&
            localStorage.getItem(MUTE_KEY) === 'off',
    );

    // Unlock audio on the first click or key press (browser autoplay rule).
    useEffect(() => {
        const unlock = () => {
            const ctx = audio();

            if (ctx && ctx.state === 'suspended') void ctx.resume();
        };
        window.addEventListener('pointerdown', unlock);
        window.addEventListener('keydown', unlock);

        return () => {
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };
    }, []);

    useEffect(() => {
        let last: Pulse | null = null;
        let stopped = false;

        const check = async () => {
            try {
                const response = await fetch('/notifications/pulse', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok || stopped) return;

                const pulse = (await response.json()) as Pulse;
                const previous = last;
                last = pulse;

                if (!previous || localStorage.getItem(MUTE_KEY) === 'off') {
                    return;
                }

                const done = heard();
                let delay = 0;

                for (const kind of ['deposit', 'withdrawal'] as const) {
                    const at = pulse[kind];

                    if (
                        at &&
                        (!previous[kind] || at > previous[kind]!) &&
                        (!done[kind] || at > done[kind]!)
                    ) {
                        done[kind] = at;
                        localStorage.setItem(HEARD_KEY, JSON.stringify(done));
                        // Both at once: the withdrawal plays after the deposit.
                        setTimeout(() => playRequestSound(kind), delay);
                        delay = 700;
                    }
                }
            } catch {
                // Offline or signed out: try again on the next tick.
            }
        };

        void check();
        const timer = setInterval(check, POLL_MS);

        return () => {
            stopped = true;
            clearInterval(timer);
        };
    }, []);

    const toggleMuted = useCallback(() => {
        const next = !muted;
        localStorage.setItem(MUTE_KEY, next ? 'off' : 'on');
        setMuted(next);

        // Turning it on plays the deposit sound, as a test.
        if (!next) {
            void audio()
                ?.resume()
                .then(() => playRequestSound('deposit'));
        }
    }, [muted]);

    return { muted, toggleMuted };
}
