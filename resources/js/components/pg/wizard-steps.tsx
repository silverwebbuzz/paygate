import { cn } from '@/lib/utils';

/**
 * Vertical step list for multi-step forms (design: partner wizard). Steps up
 * to `reachable` can be clicked (e.g. all of them when editing); steps listed
 * in `errors` are marked red.
 */
export function WizardSteps({
    steps,
    current,
    onSelect,
    reachable = current,
    errors = [],
}: {
    steps: { title: string; description?: string }[];
    current: number;
    onSelect?: (index: number) => void;
    reachable?: number;
    errors?: number[];
}) {
    return (
        <ol className="flex flex-col gap-1">
            {steps.map((step, index) => {
                const state = errors.includes(index)
                    ? 'error'
                    : index === current
                      ? 'current'
                      : index <= reachable
                        ? 'done'
                        : 'todo';

                return (
                    <li key={step.title}>
                        <button
                            type="button"
                            disabled={!onSelect || index > reachable}
                            onClick={() => onSelect?.(index)}
                            className={cn(
                                'flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left',
                                state === 'current' && 'bg-acs',
                            )}
                        >
                            <span
                                className={cn(
                                    'grid size-6 flex-none place-items-center rounded-full border text-[11px] font-semibold',
                                    state === 'done' &&
                                        'border-ok bg-okb text-ok',
                                    state === 'current' &&
                                        'border-ac bg-ac text-white',
                                    state === 'todo' &&
                                        'border-ln bg-sf text-tx3',
                                    state === 'error' &&
                                        'border-er bg-erb text-er',
                                )}
                            >
                                {state === 'done'
                                    ? '✓'
                                    : state === 'error'
                                      ? '!'
                                      : index + 1}
                            </span>
                            <span className="min-w-0">
                                <span
                                    className={cn(
                                        'block text-[13px] font-medium',
                                        state === 'todo'
                                            ? 'text-tx3'
                                            : 'text-tx',
                                    )}
                                >
                                    {step.title}
                                </span>
                                {step.description && (
                                    <span className="block text-xs text-tx3">
                                        {step.description}
                                    </span>
                                )}
                            </span>
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}
