<?php

namespace App\Domain\Transaction;

use App\Domain\Settlement\Models\SettlementPayment;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A UTR is issued once, for one bank transfer: it can belong to one
 * transaction only, across pay-ins and payouts (decided 2026-10-19).
 *
 * - A bank UTR (approved deposit, paid payout) holds it for good, unless the
 *   transaction was declined / expired / cancelled / failed (no money moved
 *   under it). Chargebacks, refunds and returns keep it.
 * - A customer's UTR on a waiting deposit does NOT hold it against another
 *   customer or a deposit approval (decided 2026-10-21): a fraudster typing
 *   someone's UTR first must not lock the genuine customer out. Both claims
 *   are accepted and flagged (claimants()), Admin is alerted, and the bank
 *   line decides — it matches only the deposit on the account the money
 *   reached. A waiting claim still holds it against payouts and settlements.
 * - A settlement payment's bank reference (platform ⇄ branch / partner)
 *   holds it too.
 *
 * Backed by a partial unique index (transactions_unique_bank_utr) for two
 * people saving at the same moment.
 */
final class Utr
{
    /** Statuses in which a transaction no longer holds its bank UTR. */
    public const RELEASED = ['rejected', 'expired', 'cancelled', 'failed'];

    /** Pay-in statuses in which the customer's UTR is claimed. */
    public const CLAIMING = ['payment_submitted', 'payment_detected', 'under_review'];

    /**
     * The transaction that already holds this (normalised) UTR, if any.
     * `$claims`: whether a waiting customer's claim counts (not for another
     * customer or a deposit approval, see above).
     */
    public static function holder(string $utr, ?string $exceptId = null, bool $claims = true): ?Transaction
    {
        return Transaction::query()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('bank_utr_normalized', $utr)->whereNotIn('status', self::RELEASED))
                ->when($claims, fn ($query) => $query->orWhere(fn ($query) => $query->where('direction', 'payin')->where('customer_utr_normalized', $utr)->whereIn('status', self::CLAIMING))))
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Other deposits still waiting with this UTR as the customer's claim.
     *
     * @return Collection<int, Transaction>
     */
    public static function claimants(string $utr, ?string $exceptId = null): Collection
    {
        return Transaction::query()
            ->where('direction', 'payin')
            ->where('customer_utr_normalized', $utr)
            ->whereIn('status', self::CLAIMING)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * A UTR is one bank transfer: refuse one another transaction holds.
     */
    public static function ensureFree(string $utr, ?string $transactionId, string $field = 'bank_utr', bool $claims = true): void
    {
        $settlement = self::settlementWith($utr);

        if ($settlement !== null) {
            throw ValidationException::withMessages([$field => __('UTR :utr was already recorded as the bank reference of settlement :reference. One UTR is one bank transfer.', ['utr' => $utr, 'reference' => $settlement])]);
        }

        $holder = self::holder($utr, $transactionId, $claims);

        if ($holder !== null) {
            throw ValidationException::withMessages([$field => __('UTR :utr already belongs to :direction :reference (:status). One UTR is one bank transfer.', [
                'utr' => $utr,
                'direction' => $holder->direction === 'payout' ? __('payout') : __('deposit'),
                'reference' => $holder->reference,
                'status' => str_replace('_', ' ', $holder->status),
            ])]);
        }
    }

    /**
     * The settlement whose payment already used this bank reference, if any
     * (references are free text, so compared normalised).
     */
    public static function settlementWith(string $utr): ?string
    {
        return SettlementPayment::query()
            ->join('settlements', 'settlements.id', '=', 'settlement_payments.settlement_id')
            ->whereRaw("upper(regexp_replace(settlement_payments.external_reference, '[^A-Za-z0-9]', '', 'g')) = ?", [$utr])
            ->value('settlements.reference');
    }

    /**
     * Whether a database error is one of the UTR unique indexes.
     */
    public static function isDuplicateError(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'transactions_unique_bank_utr');
    }
}
