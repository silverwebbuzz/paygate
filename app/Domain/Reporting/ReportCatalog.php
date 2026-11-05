<?php

namespace App\Domain\Reporting;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Reporting\Reports\PartyReport;
use App\Domain\Reporting\Reports\Report;

/**
 * The report catalogue: the client's Branch / Partner Deposit and Withdrawal
 * reports only (decided 2026-11-05; the earlier transaction, grouped,
 * commission, balance and settlement reports were dropped). Admin gets all
 * four; a branch gets the branch ones and a partner the partner ones, each
 * limited to its own data.
 */
class ReportCatalog
{
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
