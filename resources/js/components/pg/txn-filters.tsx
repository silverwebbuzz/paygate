import { SelectInput } from '@/components/pg/field';
import { MultiSelect } from '@/components/pg/multi-select';

type Org = { id: string; code: string; name: string };

export type TxnFilterValues = {
    partner: string | null;
    branches: string[];
};

/**
 * Partner and branches (Admin), shared by the Transactions and Manual
 * Deposit lists (TransactionController::filters()). The period is a
 * separate PeriodFilter.
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
        </>
    );
}
