<?php

namespace App\Domain\Reconciliation\Enums;

/**
 * Why a bank statement line didn't match cleanly (Requirements F8, §7.7).
 * Every case waits in the Unsettled UTR queue until a
 * person resolves it.
 */
enum CaseType: string
{
    case UtrNotFound = 'utr_not_found';
    case AmountMismatch = 'amount_mismatch';
    // The deposit belongs to another branch: only Admin can move it.
    case WrongBranch = 'wrong_branch';
    // The deposit is on another account of the same branch (client request
    // 2026-11-17: a different thing from a wrong branch).
    case WrongAccount = 'wrong_account';
    case DuplicateUtr = 'duplicate_utr';
    case LatePayment = 'late_payment';
    case ManualReview = 'manual_review';
    // Kept in the database for later use; not raised in Phase 9.
    case EntryWithoutTransaction = 'entry_without_transaction';
    case TransactionWithoutEntry = 'transaction_without_entry';
    case EntryBeforeTransaction = 'entry_before_transaction';

    public function label(): string
    {
        return match ($this) {
            self::UtrNotFound => 'UTR not found',
            self::AmountMismatch => 'Amount mismatch',
            self::WrongBranch => 'Wrong branch',
            self::WrongAccount => 'Wrong bank account',
            self::DuplicateUtr => 'Duplicate UTR',
            self::LatePayment => 'Credit for a closed deposit',
            self::ManualReview => 'Needs review',
            self::EntryWithoutTransaction => 'Bank line without a transaction',
            self::TransactionWithoutEntry => 'Transaction without a bank line',
            self::EntryBeforeTransaction => 'Bank line before the transaction',
        };
    }
}
