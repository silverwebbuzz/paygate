<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\AlertDispatcher;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Support\Crypto\BlindIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a branch's bank / UPI account.
 *
 * - Numbers are stored encrypted with a blind index (uniqueness across all
 *   branches) and last-4 digits for display; they are never logged.
 * - A new account goes straight to Admin for verification.
 * - Changing the details customers pay to (holder, bank, IFSC, account
 *   number, UPI ID) sends a verified account back for verification.
 * - Per-transaction limits must sit inside the branch's deposit limits.
 */
class SavePaymentAccount
{
    /** Changing any of these needs a new verification. */
    private const VERIFIED_DETAILS = [
        'account_holder_name', 'is_bank_enabled', 'bank_name', 'ifsc', 'account_number_hash',
        'is_upi_enabled', 'upi_id_hash', 'upi_code',
    ];

    /**
     * @param  array<string, mixed>  $data  columns plus plain `account_number` / `upi_id`
     *                                      (null on edit = unchanged)
     */
    public function handle(User $actor, Branch $branch, ?PaymentAccount $account, array $data): PaymentAccount
    {
        $account ??= new PaymentAccount(['branch_id' => $branch->id, 'created_by' => $actor->id]);
        $isNew = ! $account->exists;

        $accountNumber = self::normaliseAccountNumber($data['account_number'] ?? null);
        $upiId = self::normaliseUpiId($data['upi_id'] ?? null);
        unset($data['account_number'], $data['upi_id']);

        $account->fill($data);
        $account->ifsc = $account->ifsc === null ? null : strtoupper($account->ifsc);

        if ($accountNumber !== null) {
            $account->account_number_encrypted = $accountNumber;
            $account->account_number_hash = BlindIndex::of('bank_account', $accountNumber);
            $account->account_number_last4 = substr($accountNumber, -4);
        }

        if ($upiId !== null) {
            $account->upi_id_encrypted = $upiId;
            $account->upi_id_hash = BlindIndex::of('upi_id', $upiId);
            $account->upi_id_last4 = substr(explode('@', $upiId)[0], -4);
        }

        if (! $account->is_upi_enabled) {
            $account->is_qr_enabled = false;
        }

        $this->ensureComplete($account);
        $this->ensureUnique($account);
        $this->ensureInsideBranchLimits($account, $branch);

        $needsVerification = $isNew || $account->isDirty(self::VERIFIED_DETAILS) || $account->status === AccountStatus::Rejected;

        if ($needsVerification) {
            $account->status = AccountStatus::VerificationPending;
            $account->verified_at = null;
            $account->verified_by = null;
            $account->rejected_reason = null;
        }

        $changed = array_keys($account->getDirty());

        if (! $isNew && $changed === []) {
            return $account;
        }

        $old = $isNew ? [] : $this->safe(array_intersect_key($account->getOriginal(), array_flip($changed)));

        DB::transaction(function () use ($actor, $account, $isNew, $changed, $old) {
            $account->save();

            AuditLog::record($isNew ? 'payment_account.created' : 'payment_account.updated', $account, $old, $this->safe($account->only($changed)), $actor);

            if (in_array('status', $changed, true) || $isNew) {
                app(AlertDispatcher::class)->accountSaved($account);
            }
        });

        return $account;
    }

    public static function normaliseAccountNumber(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_replace('/[\s-]+/', '', $value) ?? $value;
    }

    public static function normaliseUpiId(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    private function ensureComplete(PaymentAccount $account): void
    {
        if (! $account->is_bank_enabled && ! $account->is_upi_enabled) {
            throw ValidationException::withMessages(['is_bank_enabled' => __('Enable bank transfer, UPI or both.')]);
        }

        if ($account->is_bank_enabled && ($account->account_number_hash === null || $account->ifsc === null || $account->bank_name === null)) {
            throw ValidationException::withMessages(['account_number' => __('Bank name, IFSC and account number are needed for bank transfers.')]);
        }

        if ($account->is_upi_enabled && $account->upi_id_hash === null) {
            throw ValidationException::withMessages(['upi_id' => __('Enter the UPI ID.')]);
        }
    }

    private function ensureUnique(PaymentAccount $account): void
    {
        $others = PaymentAccount::query()->when($account->exists, fn ($query) => $query->whereKeyNot($account->id));

        if ($account->is_bank_enabled && (clone $others)->where(['account_number_hash' => $account->account_number_hash, 'ifsc' => $account->ifsc])->exists()) {
            throw ValidationException::withMessages(['account_number' => __('This bank account is already registered.')]);
        }

        if ($account->upi_id_hash !== null && (clone $others)->where('upi_id_hash', $account->upi_id_hash)->exists()) {
            throw ValidationException::withMessages(['upi_id' => __('This UPI ID is already registered.')]);
        }
    }

    private function ensureInsideBranchLimits(PaymentAccount $account, Branch $branch): void
    {
        if ($account->min_amount !== null && $account->max_amount !== null && $account->min_amount > $account->max_amount) {
            throw ValidationException::withMessages(['max_amount' => __('The maximum must be at least the minimum.')]);
        }

        if ($branch->deposit_min_amount !== null && $account->min_amount !== null && $account->min_amount < $branch->deposit_min_amount) {
            throw ValidationException::withMessages(['min_amount' => __('Below the branch minimum of ₹:amount.', ['amount' => number_format($branch->deposit_min_amount / 100, 2)])]);
        }

        if ($branch->deposit_max_amount !== null && $account->max_amount !== null && $account->max_amount > $branch->deposit_max_amount) {
            throw ValidationException::withMessages(['max_amount' => __('Above the branch maximum of ₹:amount.', ['amount' => number_format($branch->deposit_max_amount / 100, 2)])]);
        }
    }

    /**
     * Audit values without secrets (the encrypted numbers and their hashes).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function safe(array $values): array
    {
        unset($values['account_number_encrypted'], $values['upi_id_encrypted'], $values['account_number_hash'], $values['upi_id_hash']);

        foreach ($values as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $values[$key] = $value->value;
            }
        }

        return $values;
    }
}
