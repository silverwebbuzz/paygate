<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerJournal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only way money is booked (ArchitectureTest enforces it).
 *
 * Every posting is one journal whose entries sum to zero (also enforced by a
 * database trigger at commit), with the cached balances updated in the same
 * database transaction. Balance rows are locked in ascending id order so two
 * postings can never deadlock (Database.md §5).
 *
 * Sign convention: positive = the platform owes the party; negative = the
 * party owes the platform. Platform accounts are positive for income.
 */
class Ledger
{
    public const PARTNER_POSITION = 'partner_position';

    public const BRANCH_POSITION = 'branch_position';

    public const PLATFORM_MARGIN = 'platform_margin';

    public const PLATFORM_ADJUSTMENTS = 'platform_adjustments';

    public const SETTLEMENT_CLEARING = 'settlement_clearing';

    /**
     * The account id for a pair position (created on first use) or a
     * platform account.
     */
    public function account(string $kind, ?string $partnerId = null, ?string $branchId = null): string
    {
        $isPosition = in_array($kind, [self::PARTNER_POSITION, self::BRANCH_POSITION], true);

        if ($isPosition !== ($partnerId !== null && $branchId !== null)) {
            throw new InvalidArgumentException("{$kind} needs ".($isPosition ? 'a partner and a branch' : 'no owner').'.');
        }

        $existing = LedgerAccount::query()
            ->where('kind', $kind)
            ->where('partner_id', $partnerId)
            ->where('branch_id', $branchId)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) (new LedgerAccount)->newUniqueId();

        DB::table('ledger_accounts')->insertOrIgnore(['id' => $id, 'kind' => $kind, 'partner_id' => $partnerId, 'branch_id' => $branchId]);

        $id = (string) LedgerAccount::query()->where(['kind' => $kind, 'partner_id' => $partnerId, 'branch_id' => $branchId])->value('id');

        DB::table('ledger_balances')->insertOrIgnore(['ledger_account_id' => $id]);

        return $id;
    }

    /**
     * Books one balanced journal. Call inside the caller's DB transaction.
     *
     * @param  array<string, int>  $lines  ledger account id => signed amount (paise)
     */
    public function post(string $type, array $lines, ?string $transactionId = null, ?string $description = null, ?string $userId = null): string
    {
        $lines = array_filter($lines, fn (int $amount) => $amount !== 0);

        if ($lines === [] || array_sum($lines) !== 0) {
            throw new RuntimeException('A journal must have entries that sum to zero.');
        }

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Ledger postings must run inside a database transaction.');
        }

        $accountIds = array_keys($lines);
        sort($accountIds);

        // Lock the balances in a fixed order (deadlock-free), then book.
        DB::table('ledger_balances')->whereIn('ledger_account_id', $accountIds)->orderBy('ledger_account_id')->lockForUpdate()->get();

        $journal = LedgerJournal::create([
            'type' => $type,
            'transaction_id' => $transactionId,
            'description' => $description,
            'posted_at' => now(),
            'created_by' => $userId,
            'request_id' => Context::get('request_id'),
        ]);

        foreach ($accountIds as $accountId) {
            LedgerEntry::create(['journal_id' => $journal->id, 'ledger_account_id' => $accountId, 'amount' => $lines[$accountId]]);

            DB::update('UPDATE ledger_balances SET balance = balance + ?, updated_at = now() WHERE ledger_account_id = ?', [$lines[$accountId], $accountId]);
        }

        return $journal->id;
    }

    /**
     * Current balance of an account (0 when it has none yet).
     */
    public function balance(string $kind, ?string $partnerId = null, ?string $branchId = null): int
    {
        return (int) DB::table('ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->where('a.kind', $kind)
            ->where('a.partner_id', $partnerId)
            ->where('a.branch_id', $branchId)
            ->value('b.balance');
    }

    /**
     * The journals booked for a transaction, for display.
     *
     * @return array<int, array{id: string, type: string, posted_at: string, entries: array<int, array{kind: string, partner_id: string|null, branch_id: string|null, amount: int}>}>
     */
    public function journalsForTransaction(string $transactionId): array
    {
        return LedgerJournal::query()
            ->where('transaction_id', $transactionId)
            ->with('entries.account')
            ->orderBy('posted_at')
            ->get()
            ->map(fn (LedgerJournal $journal) => [
                'id' => $journal->id,
                'type' => $journal->type,
                'posted_at' => $journal->posted_at->toIso8601String(),
                'entries' => $journal->entries->map(fn (LedgerEntry $entry) => [
                    'kind' => $entry->account->kind,
                    'partner_id' => $entry->account->partner_id,
                    'branch_id' => $entry->account->branch_id,
                    'amount' => $entry->amount,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
