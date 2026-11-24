import { MultiSelect } from '@/components/pg/multi-select';

type Org = { id: string; code: string; name: string };

export type TxnFilterValues = {
    partners: string[];
    branches: string[];
};

/**
 * Partners (Admin, branches) and branches (Admin), one or more of each,
 * shared by the Transactions, Manual Deposit, Chargebacks and Payout
 * returns lists (TransactionController::filters()). The period is a
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
                <MultiSelect
                    noun="partners"
                    items={partners}
                    selected={value.partners}
                    onChange={(ids) => onChange({ partners: ids })}
                    className="h-[30px] w-[220px] flex-none rounded-[7px] text-[12.5px]"
                />
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
