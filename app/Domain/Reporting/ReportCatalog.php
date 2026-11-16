<?php

namespace App\Domain\Reporting;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Ledger\Ledger;
use App\Domain\Payout\PartnerBalance;
use App\Domain\Reporting\Reports\BalancesReport;
use App\Domain\Reporting\Reports\CommissionReport;
use App\Domain\Reporting\Reports\GroupedReport;
use App\Domain\Reporting\Reports\PartyReport;
use App\Domain\Reporting\Reports\Report;
use App\Domain\Reporting\Reports\SettlementsReport;
use App\Domain\Reporting\Reports\TransactionsReport;

/**
 * The report catalogue: the client's Branch / Partner Deposit and Withdrawal
 * reports first (2026-11-03), then the transaction, grouped, commission,
 * balance and settlement reports (G-49). Those were dropped on 2026-11-05
 * and brought back at the client's request on 2026-11-16. Each report says
 * which portals get it and limits the rows to the viewer's own data; branch
 * and partner Reports pages are closed for now (SectionRollout::RETIRED).
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
            new PartyReport('payin', 'branch'),
            new PartyReport('payin', 'partner'),
            new PartyReport('payout', 'branch'),
            new PartyReport('payout', 'partner'),
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
