import { SelectInput } from '@/components/pg/field';
import { MultiSelect } from '@/components/pg/multi-select';

type Org = { id: string; code: string; name: string };

export type TxnFilterValues = {
    partner: string | null;
    branches: string[];
    from: string | null;
    to: string | null;
};

/**
 * Partner, branches (Admin) and a created-date range, shared by the
 * Transactions and Manual Deposit lists (TransactionController::filters()).
 */
export function TxnFilters({
    value,
    partners,
    branches,
    onChange,
}: {
    value: TxnFilterValues;
    partners: Org[];
    branches: Org[];
    onChange: (next: Partial<TxnFilterValues>) => void;
}) {
    const dated = value.from !== null || value.to !== null;

    return (
        <>
            {partners.length > 0 && (
                <SelectInput
                    className="h-[30px] w-[200px] text-[12.5px]"
                    value={value.partner ?? ''}
                    onChange={(event) =>
                        onChange({ partner: event.target.value || null })
                    }
                >
                    <option value="">All partners</option>
                    {partners.map((partner) => (
                        <option key={partner.id} value={partner.id}>
                            {partner.code} · {partner.name}
                        </option>
                    ))}
                </SelectInput>
            )}
            {branches.length > 0 && (
                <MultiSelect
                    noun="branches"
                    items={branches}
                    selected={value.branches}
                    onChange={(ids) => onChange({ branches: ids })}
                    className="h-[30px] w-[220px] flex-none rounded-[7px] text-[12.5px]"
                />
            )}
            <span className="flex items-center gap-1 text-xs text-tx3">
                <input
                    type="date"
                    aria-label="From date"
                    value={value.from ?? ''}
                    max={value.to ?? undefined}
                    onChange={(event) =>
                        onChange({ from: event.target.value || null })
                    }
                    className="h-[30px] rounded-[7px] border border-ln bg-sf px-2 text-[12.5px] text-tx"
                />
                –
                <input
                    type="date"
                    aria-label="To date"
                    value={value.to ?? ''}
                    min={value.from ?? undefined}
                    onChange={(event) =>
                        onChange({ to: event.target.value || null })
                    }
                    className="h-[30px] rounded-[7px] border border-ln bg-sf px-2 text-[12.5px] text-tx"
                />
                {dated && (
                    <button
                        type="button"
                        onClick={() => onChange({ from: null, to: null })}
                        className="font-medium text-ac"
                    >
                        All dates
                    </button>
                )}
            </span>
        </>
    );
}
