<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Scope;

/**
 * One report of the catalogue (decided 2026-09-28, G-49): rows for a scope
 * and period, the columns that scope may see, and totals. Used for the
 * on-screen preview and for exports (CSV / Excel).
 *
 * Column types: text, date (ISO), money (paise), signed (paise, + = the
 * platform owes the party), count, percent, status.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /**
     * @return list<UserType>
     */
    public function portals(): array
    {
        return [UserType::Admin, UserType::Partner, UserType::Branch];
    }

    /**
     * Whether the report is about a period (balances are "now").
     */
    public function usesPeriod(): bool
    {
        return true;
    }

    /**
     * Filters the page offers: key => label (options come from the page).
     *
     * @return array<string, string>
     */
    public function filters(Scope $scope): array
    {
        return [];
    }

    /**
     * @return list<array{key: string, label: string, type: string}>
     */
    abstract public function columns(Scope $scope): array;

    /**
     * @param  array<string, string|null>  $filters
     * @return iterable<array<string, mixed>>
     */
    abstract public function rows(Scope $scope, Period $period, array $filters): iterable;

    /**
     * Sums of the money / count columns, plus `_count` (the number of rows).
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int|null>
     */
    public function totals(Scope $scope, Period $period, array $filters): array
    {
        $totals = ['_count' => 0];
        $summed = array_column(array_filter($this->columns($scope), fn (array $column) => in_array($column['type'], ['money', 'signed', 'count'], true)), 'key');

        foreach ($summed as $key) {
            $totals[$key] = 0;
        }

        foreach ($this->rows($scope, $period, $filters) as $row) {
            $totals['_count']++;

            foreach ($summed as $key) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    protected function column(string $key, string $label, string $type = 'text'): array
    {
        return compact('key', 'label', 'type');
    }
}
