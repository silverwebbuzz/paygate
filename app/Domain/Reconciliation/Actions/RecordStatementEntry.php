<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\StatementMatcher;
use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual A/C statement entry (Req 18, design "New statement line"): a branch
 * operator or Admin types one line of a branch account's bank statement.
 * It is matched straight away (StatementMatcher). Lines are never edited or
 * deleted; a wrong one is closed from its case ("not a customer payment").
 */
class RecordStatementEntry
{
    public function __construct(private StatementMatcher $matcher) {}

    public function handle(User $actor, PaymentAccount $account, string $date, string $direction, int $amount, ?string $utr, ?string $description): StatementEntry
    {
        $valueDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.business_timezone'));

        if ($valueDate === null || $valueDate->isAfter(CarbonImmutable::now(config('app.business_timezone')))) {
            throw ValidationException::withMessages(['value_date' => __('The date can’t be in the future.')]);
        }

        $normalised = $utr === null || trim($utr) === '' ? null : Transaction::normaliseUtr($utr);

        if ($normalised !== null && preg_match(SubmitPayinProof::UTR_PATTERN, $normalised) !== 1) {
            throw ValidationException::withMessages(['utr' => __('Enter the UTR / reference number only (6–22 letters or digits); put other text in the description.')]);
        }

        if ($normalised === null && trim((string) $description) === '') {
            throw ValidationException::withMessages(['utr' => __('Enter the UTR, or a description of the line.')]);
        }

        $hash = StatementEntry::rowHash($account->id, $valueDate->toDateString(), $direction, $amount, $normalised, $description);
        $existing = StatementEntry::query()->where(['payment_account_id' => $account->id, 'row_hash' => $hash])->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['utr' => __('This line is already in the statement (added :when).', ['when' => $existing->created_at->timezone(config('app.business_timezone'))->format('d M Y, H:i')])]);
        }

        return DB::transaction(function () use ($actor, $account, $valueDate, $direction, $amount, $utr, $normalised, $description, $hash) {
            $entry = StatementEntry::create([
                'branch_id' => $account->branch_id,
                'payment_account_id' => $account->id,
                'value_date' => $valueDate->toDateString(),
                'entry_direction' => $direction,
                'amount' => $amount,
                'utr' => $utr === null ? null : mb_substr(trim($utr), 0, 50),
                'utr_normalized' => $normalised,
                'description' => $description === null ? null : mb_substr(trim($description), 0, 500),
                'row_hash' => $hash,
                'status' => 'imported',
                'created_by' => $actor->id,
            ]);

            $this->matcher->match($entry);

            AuditLog::record('statement.entry_added', $entry, [], [
                'account' => $account->label,
                'direction' => $direction,
                'amount' => $amount,
                'utr' => $normalised,
                'status' => $entry->status,
            ], $actor);

            return $entry;
        });
    }
}
