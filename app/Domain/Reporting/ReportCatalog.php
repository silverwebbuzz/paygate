<?php

namespace App\Domain\Reporting;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Ledger\Ledger;
use App\Domain\Payout\PartnerBalance;
use App\Domain\Reporting\Reports\BalancesReport;
use App\Domain\Reporting\Reports\CommissionReport;
use App\Domain\Reporting\Reports\GroupedReport;
use App\Domain\Reporting\Reports\Report;
use App\Domain\Reporting\Reports\SettlementsReport;
use App\Domain\Reporting\Reports\TransactionsReport;

/**
 * The report catalogue (decided 2026-09-28, G-49: the full list, merged
 * into eight reports). Each portal gets the ones about its own data.
 */
class ReportCatalog
{
    public function __construct(private Ledger $ledger, private PartnerBalance $balances) {}

    /**
     * @return array<string, Report>
     */
    public function all(): array
    {
        $reports = [
            new TransactionsReport('payin'),
            new TransactionsReport('payout'),
            new GroupedReport('partners', $this->ledger),
            new GroupedReport('branches', $this->ledger),
            new GroupedReport('pairs', $this->ledger),
            new CommissionReport,
            new BalancesReport($this->ledger, $this->balances),
            new SettlementsReport,
        ];

        $keyed = [];

        foreach ($reports as $report) {
            $keyed[$report->key()] = $report;
        }

        return $keyed;
    }

    /**
     * @return array<string, Report>
     */
    public function for(UserType $type): array
    {
        return array_filter($this->all(), fn (Report $report) => in_array($type, $report->portals(), true));
    }

    public function find(UserType $type, string $key): ?Report
    {
        return $this->for($type)[$key] ?? null;
    }
}
