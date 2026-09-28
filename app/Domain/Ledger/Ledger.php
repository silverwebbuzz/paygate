<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerJournal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
     * @param  array{adjustment_id?: string, settlement_payment_id?: string, reverses_journal_id?: string}  $links  what else the journal comes from
     */
    public function post(string $type, array $lines, ?string $transactionId = null, ?string $description = null, ?string $userId = null, array $links = []): string
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
            ...array_intersect_key($links, array_flip(['adjustment_id', 'settlement_payment_id', 'reverses_journal_id'])),
        ]);

        foreach ($accountIds as $accountId) {
            LedgerEntry::create(['journal_id' => $journal->id, 'ledger_account_id' => $accountId, 'amount' => $lines[$accountId]]);

            DB::update('UPDATE ledger_balances SET balance = balance + ?, updated_at = now() WHERE ledger_account_id = ?', [$lines[$accountId], $accountId]);
        }

        return $journal->id;
    }

    /**
     * Holds `$amount` of a position for an open payout, only if what is left
     * (balance − reserved) covers it. A conditional update, so concurrent
     * payouts wait for each other instead of overdrawing (Database.md §4).
     */
    public function reserve(string $accountId, int $amount): bool
    {
        return DB::update(
            'UPDATE ledger_balances SET reserved = reserved + ?, updated_at = now() WHERE ledger_account_id = ? AND balance - reserved >= ?',
            [$amount, $accountId, $amount],
        ) === 1;
    }

    /**
     * Gives a payout hold back (paid, failed, cancelled or moved).
     */
    public function release(string $accountId, int $amount): void
    {
        DB::update('UPDATE ledger_balances SET reserved = GREATEST(reserved - ?, 0), updated_at = now() WHERE ledger_account_id = ?', [$amount, $accountId]);
    }

    /**
     * Balance, reserved and available for each pair position of a partner.
     *
     * @return array<string, array{balance: int, reserved: int}> branch id => figures
     */
    public function partnerPositions(string $partnerId): array
    {
        return DB::table('ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->where('a.kind', self::PARTNER_POSITION)
            ->where('a.partner_id', $partnerId)
            ->get(['a.branch_id', 'b.balance', 'b.reserved'])
            ->mapWithKeys(fn (object $row) => [(string) $row->branch_id => ['balance' => (int) $row->balance, 'reserved' => (int) $row->reserved]])
            ->all();
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

    /**
     * The position accounts of one party (a partner's or a branch's pair
     * accounts), with the counterpart of each pair.
     *
     * @return list<array{id: string, partner_id: string, branch_id: string}>
     */
    public function partyAccounts(string $partyType, string $partyId): array
    {
        return array_values(DB::table('ledger_accounts')
            ->where('kind', $partyType === 'partner' ? self::PARTNER_POSITION : self::BRANCH_POSITION)
            ->where($partyType === 'partner' ? 'partner_id' : 'branch_id', $partyId)
            ->orderBy('created_at')
            ->get(['id', 'partner_id', 'branch_id'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'partner_id' => (string) $row->partner_id, 'branch_id' => (string) $row->branch_id])
            ->all());
    }

    /**
     * How accounts moved in [start, end) by journal posting time: the
     * opening balance, the sum per journal type, the transactions booked,
     * and the closing balance (opening + movements).
     *
     * @param  list<string>  $accountIds
     * @return array<string, array{opening: int, closing: int, by_type: array<string, int>, transactions: list<string>}>
     */
    public function statement(array $accountIds, CarbonInterface $start, CarbonInterface $end): array
    {
        $opening = [];
        $byType = [];
        $transactions = [];

        if ($accountIds !== []) {
            $entries = fn () => DB::table('ledger_entries as e')
                ->join('ledger_journals as j', 'j.id', '=', 'e.journal_id')
                ->whereIn('e.ledger_account_id', $accountIds);

            foreach ($entries()->where('j.posted_at', '<', $start)->groupBy('e.ledger_account_id')->get(['e.ledger_account_id', DB::raw('SUM(e.amount) AS total')]) as $row) {
                $opening[(string) $row->ledger_account_id] = (int) $row->total;
            }

            $inPeriod = fn () => $entries()->where('j.posted_at', '>=', $start)->where('j.posted_at', '<', $end);

            foreach ($inPeriod()->groupBy('e.ledger_account_id', 'j.type')->get(['e.ledger_account_id', 'j.type', DB::raw('SUM(e.amount) AS total')]) as $row) {
                $byType[(string) $row->ledger_account_id][(string) $row->type] = (int) $row->total;
            }

            foreach ($inPeriod()->whereNotNull('j.transaction_id')->whereIn('j.type', ['payin_success', 'payout_success'])->distinct()->get(['e.ledger_account_id', 'j.transaction_id']) as $row) {
                $transactions[(string) $row->ledger_account_id][] = (string) $row->transaction_id;
            }
        }

        $result = [];

        foreach ($accountIds as $id) {
            $types = $byType[$id] ?? [];
            $result[$id] = [
                'opening' => $opening[$id] ?? 0,
                'closing' => ($opening[$id] ?? 0) + array_sum($types),
                'by_type' => $types,
                'transactions' => $transactions[$id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * When the first of these accounts was booked (null if never).
     *
     * @param  list<string>  $accountIds
     */
    public function firstPostingAt(array $accountIds): ?CarbonImmutable
    {
        $first = DB::table('ledger_entries as e')
            ->join('ledger_journals as j', 'j.id', '=', 'e.journal_id')
            ->whereIn('e.ledger_account_id', $accountIds)
            ->min('j.posted_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first);
    }

    /**
     * Current balance and payout holds of one account.
     *
     * @return array{balance: int, reserved: int}
     */
    public function figures(string $accountId): array
    {
        $row = DB::table('ledger_balances')->where('ledger_account_id', $accountId)->first(['balance', 'reserved']);

        return ['balance' => (int) ($row->balance ?? 0), 'reserved' => (int) ($row->reserved ?? 0)];
    }

    /**
     * Balance and holds of every position account of a branch, per partner.
     *
     * @return array<string, array{balance: int, reserved: int}> partner id => figures
     */
    public function branchPositions(string $branchId): array
    {
        return DB::table('ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->where('a.kind', self::BRANCH_POSITION)
            ->where('a.branch_id', $branchId)
            ->get(['a.partner_id', 'b.balance', 'b.reserved'])
            ->mapWithKeys(fn (object $row) => [(string) $row->partner_id => ['balance' => (int) $row->balance, 'reserved' => (int) $row->reserved]])
            ->all();
    }

    /**
     * Which pair each position account belongs to.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array{partner_id: string|null, branch_id: string|null}>
     */
    public function owners(array $accountIds): array
    {
        return DB::table('ledger_accounts')
            ->whereIn('id', $accountIds)
            ->get(['id', 'partner_id', 'branch_id'])
            ->mapWithKeys(fn (object $row) => [(string) $row->id => ['partner_id' => $row->partner_id, 'branch_id' => $row->branch_id]])
            ->all();
    }

    /**
     * Current position totals per party (sum over its pair accounts):
     * positive = the platform owes the party.
     *
     * @return array<string, int> partner id or branch id => total
     */
    public function partyTotals(string $partyType): array
    {
        $column = $partyType === 'partner' ? 'a.partner_id' : 'a.branch_id';

        return DB::table('ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->where('a.kind', $partyType === 'partner' ? self::PARTNER_POSITION : self::BRANCH_POSITION)
            ->groupBy($column)
            ->get([DB::raw("{$column} AS party_id"), DB::raw('SUM(b.balance) AS total')])
            ->mapWithKeys(fn (object $row) => [(string) $row->party_id => (int) $row->total])
            ->all();
    }

    /**
     * What parties owe the platform and what it owes them right now, over
     * all partner and branch positions (optionally one party's only).
     *
     * @return array{to_receive: int, to_pay: int}
     */
    public function unsettled(?string $partnerId = null, ?string $branchId = null): array
    {
        $row = DB::table('ledger_balances as b')
            ->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')
            ->when($partnerId !== null, fn ($query) => $query->where(['a.kind' => self::PARTNER_POSITION, 'a.partner_id' => $partnerId]))
            ->when($branchId !== null, fn ($query) => $query->where(['a.kind' => self::BRANCH_POSITION, 'a.branch_id' => $branchId]))
            ->when($partnerId === null && $branchId === null, fn ($query) => $query->whereIn('a.kind', [self::PARTNER_POSITION, self::BRANCH_POSITION]))
            ->first([DB::raw('COALESCE(SUM(CASE WHEN b.balance < 0 THEN -b.balance ELSE 0 END), 0) AS to_receive'), DB::raw('COALESCE(SUM(CASE WHEN b.balance > 0 THEN b.balance ELSE 0 END), 0) AS to_pay')]);

        return ['to_receive' => (int) ($row->to_receive ?? 0), 'to_pay' => (int) ($row->to_pay ?? 0)];
    }

    /**
     * Every partner↔branch pair's two positions now.
     *
     * @return array<string, array{partner_id: string, branch_id: string, partner: int, reserved: int, branch: int}> "partner|branch" => figures
     */
    public function pairPositions(): array
    {
        $pairs = [];

        foreach (DB::table('ledger_balances as b')->join('ledger_accounts as a', 'a.id', '=', 'b.ledger_account_id')->whereIn('a.kind', [self::PARTNER_POSITION, self::BRANCH_POSITION])->get(['a.kind', 'a.partner_id', 'a.branch_id', 'b.balance', 'b.reserved']) as $row) {
            $key = $row->partner_id.'|'.$row->branch_id;
            $pairs[$key] ??= ['partner_id' => (string) $row->partner_id, 'branch_id' => (string) $row->branch_id, 'partner' => 0, 'reserved' => 0, 'branch' => 0];

            if ($row->kind === self::PARTNER_POSITION) {
                $pairs[$key]['partner'] = (int) $row->balance;
                $pairs[$key]['reserved'] = (int) $row->reserved;
            } else {
                $pairs[$key]['branch'] = (int) $row->balance;
            }
        }

        return $pairs;
    }

    /**
     * The id of a transaction's journal of one type (e.g. its success
     * booking), for reversals to point at.
     */
    public function journalId(string $transactionId, string $type): ?string
    {
        $id = DB::table('ledger_journals')->where(['transaction_id' => $transactionId, 'type' => $type])->value('id');

        return $id === null ? null : (string) $id;
    }
}
