<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
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
 * It is matched straight away (StatementMatcher). A UTR already on another
 * bank line is refused here (client request 2026-11-14); imported files
 * still flag such a line as Duplicate UTR. Lines are never deleted; a wrong
 * one is closed from its case ("not a customer payment"), moved to the right
 * account of the branch (ResolveCase::moveLine, 2026-10-27), or, by Admin
 * only, given the right UTR / amount (EditStatementLine, 2026-11-14).
 */
class RecordStatementEntry
{
    public function __construct(private StatementMatcher $matcher, private AutoApproveDeposit $autoApprove) {}

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

        if ($normalised !== null && ($twin = $this->matcher->lineWithUtr($normalised)) !== null) {
            // Another branch's UTR typed here may be a mistake or fraud: Admin hears of it.
            if ($twin->branch_id !== $account->branch_id) {
                app(AlertDispatcher::class)->utrConflict($normalised, __(':name tried to add a bank line with UTR :utr to :branch · :account; it is already on a line of :other. Refused.', [
                    'name' => $actor->name,
                    'utr' => $normalised,
                    'branch' => $account->branch->name ?? '—',
                    'account' => $account->label,
                    'other' => $twin->branch->name ?? '—',
                ]));
            }

            throw ValidationException::withMessages(['utr' => self::utrTaken($actor, $twin)]);
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
            $this->autoApprove->forLine($entry);

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

    /**
     * Why a UTR is refused. Another branch's line is not described to a
     * branch user (it isn't theirs to see).
     */
    public static function utrTaken(User $actor, StatementEntry $twin): string
    {
        $tz = config('app.business_timezone');

        if (! $actor->isType(UserType::Admin) && $twin->branch_id !== $actor->branch_id) {
            return __('UTR :utr is already on a bank line. One UTR is one bank transfer: check the number, or ask Admin.', ['utr' => $twin->utr_normalized]);
        }

        return __('UTR :utr is already on a :direction line of ₹:amount (:account, :date, added :when). One UTR is one bank transfer: check the number; Admin can correct a wrong line.', [
            'utr' => $twin->utr_normalized,
            'direction' => $twin->entry_direction,
            'amount' => number_format($twin->amount / 100, 2),
            'account' => $twin->paymentAccount->label ?? '—',
            'date' => $twin->value_date->format('d M Y'),
            'when' => $twin->created_at->timezone($tz)->format('d M Y, H:i'),
        ]);
    }
}
