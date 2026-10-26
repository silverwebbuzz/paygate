<?php

namespace App\Domain\Notification;

use App\Domain\Allocation\AccountAvailability;
use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Ledger\Ledger;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Settlement\Models\Adjustment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\Chargeback;
use App\Domain\Transaction\Models\PayoutReturn;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Models\TransactionReversal;
use App\Domain\Webhook\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;

/**
 * Who hears about what (G-47). Each method picks the people who can act
 * (portal, organisation, permission) and words the alert. Called from the
 * actions where the event happens; everything is queued after commit.
 */
class AlertDispatcher
{
    /** A partly paid payout with no new transfer for this long alerts the branch and Admin. */
    public const PART_PAID_HOURS = 24;

    private const REPEAT_MINUTES = 15;

    public function __construct(
        private Alerts $alerts,
        private Settings $settings,
        private Ledger $ledger,
        private AccountAvailability $availability,
    ) {}

    public function payoutAssigned(Transaction $payout): void
    {
        $this->alerts->send(
            Alert::PayoutAssigned,
            $this->alerts->recipients(Permission::PayoutsProcess, UserType::Branch, $payout->branch_id),
            __('Payout to pay: ₹:amount', ['amount' => $this->money($payout->amount)]),
            __(':reference is waiting in Manual Payout.', ['reference' => $payout->reference]),
            Alerts::link('branch.payouts.index'),
        );
    }

    /**
     * @param  array{ledger_account_id: string, needed: int}  $reservation
     */
    public function payoutOnShortBranch(Transaction $payout, array $reservation): void
    {
        $figures = $this->ledger->figures($reservation['ledger_account_id']);

        if ($figures['balance'] - $figures['reserved'] >= 0) {
            return;
        }

        $branch = Branch::query()->whereKey($payout->branch_id)->value('code');
        $partner = Partner::query()->whereKey($payout->partner_id)->value('name');
        $held = max(0, $figures['balance'] - ($figures['reserved'] - $reservation['needed']));

        $this->alerts->send(
            Alert::PayoutShortBranch,
            $this->alerts->recipients(Permission::PayoutsProcess, UserType::Admin),
            __('Payout :reference may need moving', ['reference' => $payout->reference]),
            __(':branch holds ₹:held of :partner’s balance, but this payout needs ₹:needed. If the branch tells you it has no money, move the payout to another branch in Manual Payout.', [
                'branch' => $branch,
                'held' => $this->money($held),
                'partner' => $partner,
                'needed' => $this->money($reservation['needed']),
            ]),
            Alerts::link('admin.payouts.index', ['txn' => $payout->id]),
        );
    }

    public function payoutNoBranch(Partner $partner, int $amount): void
    {
        if (! Cache::add("alerts:payout-no-branch:{$partner->id}", true, now()->addMinutes(self::REPEAT_MINUTES))) {
            return;
        }

        $this->alerts->send(
            Alert::PayoutNoBranch,
            $this->alerts->recipients(Permission::PayoutsProcess, UserType::Admin),
            __(':partner payouts are being refused', ['partner' => $partner->name]),
            __('No branch could take a ₹:amount payout. Check the mapped branches: withdrawals on, amount range, today’s withdrawal limits and withdrawal rates.', ['amount' => $this->money($amount)]),
            Alerts::link('admin.branches.index'),
        );
    }

    public function payinNoAccount(Transaction $payin, Partner $partner, ?string $method = null): void
    {
        if (! Cache::add("alerts:payin-no-account:{$partner->id}", true, now()->addMinutes(self::REPEAT_MINUTES))) {
            return;
        }

        $reasons = $this->availability->reasons($partner, $payin->amount);

        $this->alerts->send(
            Alert::PayinNoAccount,
            $this->alerts->recipients(Permission::AccountsVerify, UserType::Admin),
            __('Customers of :partner can’t pay right now', ['partner' => $partner->name]),
            __('Pay-in :reference (₹:amount:method): no bank account can take it. :reasons. On the server, php artisan paygate:why-busy :reference shows every account.', [
                'reference' => $payin->reference,
                'amount' => $this->money($payin->amount),
                'method' => $method === null ? '' : ', '.str_replace('_', ' ', $method),
                'reasons' => implode(' | ', $reasons),
            ]),
            Alerts::link('admin.accounts.index'),
        );
    }

    public function payinAmountDiffers(Transaction $payin, User $approver): void
    {
        $branch = Branch::query()->whereKey($payin->branch_id)->value('code');

        $this->alerts->send(
            Alert::PayinAmountDiffers,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin, except: $approver),
            __('Deposit :reference approved for ₹:received of ₹:order', [
                'reference' => $payin->reference,
                'received' => $this->money($payin->amount),
                'order' => $this->money($payin->orderAmount()),
            ]),
            __(':name approved it for the amount that reached branch :branch. Note: :note', [
                'name' => $approver->username ?? $approver->name,
                'branch' => $branch ?? '—',
                'note' => $payin->status_note ?? '—',
            ]),
            Alerts::link('admin.transactions.index', ['txn' => $payin->id]),
        );
    }

    /**
     * The same UTR showed up twice (two waiting deposits, a deposit approved
     * while another still claims it, or two bank lines): a typing mistake or
     * fraud. Admin decides; nothing is approved automatically.
     */
    public function utrConflict(string $utr, string $body, ?Transaction $txn = null, ?ReconciliationCase $case = null): void
    {
        $this->alerts->send(
            Alert::UtrConflict,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin),
            __('UTR :utr used twice — check for fraud', ['utr' => $utr]),
            $body,
            match (true) {
                $case !== null => Alerts::link('admin.cases.index', ['case' => $case->id]),
                $txn !== null => Alerts::link('admin.transactions.index', ['txn' => $txn->id]),
                default => null,
            },
        );
    }

    /**
     * Approved by hand with a bank UTR that isn't the customer's: allowed
     * (the customer may have mistyped), but it is how a wrong deposit would
     * be approved, so Admin sees each one.
     */
    public function approvedWithOtherUtr(Transaction $payin, User $approver): void
    {
        $this->alerts->send(
            Alert::ApprovedOtherUtr,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin, except: $approver),
            __('Deposit :reference approved with another UTR', ['reference' => $payin->reference]),
            __(':name (branch :branch) approved it with bank UTR :bank; the customer gave :customer. Note: :note', [
                'name' => $approver->username ?? $approver->name,
                'branch' => Branch::query()->whereKey($payin->branch_id)->value('code') ?? '—',
                'bank' => $payin->bank_utr_normalized,
                'customer' => $payin->customer_utr_normalized,
                'note' => $payin->status_note ?? '—',
            ]),
            Alerts::link('admin.transactions.index', ['txn' => $payin->id]),
        );
    }

    /**
     * Scheduled: deposits approved by hand that no bank statement line has
     * confirmed after the Global Settings hours — the money was never shown
     * arriving. Each deposit is reported once (looks back 30 days).
     *
     * @return int deposits reported
     */
    public function approvedWithoutBankLine(): int
    {
        $hours = $this->settings->unmatchedApprovalHours();

        if ($hours === 0) {
            return 0;
        }

        $unseen = Transaction::query()
            ->where(['direction' => 'payin', 'status' => 'success'])
            ->whereBetween('succeeded_at', [now()->subDays(30), now()->subHours($hours)])
            ->whereDoesntHave('statementEntry')
            ->whereDoesntHave('events', fn ($query) => $query->where('event', 'unmatched_alerted'))
            ->with('branch')
            ->get(['id', 'reference', 'branch_id', 'status', 'amount']);

        if ($unseen->isEmpty()) {
            return 0;
        }

        $this->alerts->send(
            Alert::ApprovedWithoutBankLine,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin),
            trans_choice(':count approved deposit not in any bank statement after :hours h|:count approved deposits not in any bank statement after :hours h', $unseen->count(), ['count' => $unseen->count(), 'hours' => $hours]),
            __('Ask the branch for the bank entry, or check its statement: :list.', [
                'list' => $unseen->take(10)->map(fn (Transaction $txn) => $txn->reference.' ('.($txn->branch->code ?? '—').', ₹'.$this->money($txn->amount).')')->join(', '),
            ]),
            Alerts::link('admin.transactions.index'),
        );

        foreach ($unseen as $payin) {
            TransactionEvent::record($payin, 'unmatched_alerted', $payin->status, $payin->status, 'system', null, null, ['hours' => $hours]);
        }

        return $unseen->count();
    }

    /**
     * A branch's bank line showed a deposit waiting at another branch: it was
     * moved there and approved automatically. Admin and the old branch see it.
     */
    public function depositAutoMoved(Transaction $payin, string $fromBranchId): void
    {
        $from = Branch::query()->whereKey($fromBranchId)->value('code');
        $to = Branch::query()->whereKey($payin->branch_id)->value('code');
        $title = __('Deposit :reference moved :from → :to automatically', ['reference' => $payin->reference, 'from' => $from ?? '—', 'to' => $to ?? '—']);
        $body = __('Branch :to’s bank shows UTR :utr for ₹:amount, so the deposit was moved there and approved.', [
            'to' => $to ?? '—',
            'utr' => $payin->bank_utr_normalized ?? $payin->customer_utr_normalized ?? '—',
            'amount' => $this->money($payin->amount),
        ]);

        $this->alerts->send(Alert::DepositAutoMoved, $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin), $title, $body, Alerts::link('admin.transactions.index', ['txn' => $payin->id]));
        $this->alerts->send(Alert::DepositAutoMoved, $this->alerts->recipients(Permission::PayinsApprove, UserType::Branch, $fromBranchId), $title, $body);
    }

    /**
     * Admin moved a deposit to the account the money really reached.
     */
    public function depositMoved(Transaction $payin, string $reason): void
    {
        $this->alerts->send(
            Alert::DepositMoved,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Branch, $payin->branch_id),
            __('Deposit moved to you: ₹:amount', ['amount' => $this->money($payin->amount)]),
            __(':reference was moved to your branch to check and approve. Reason: :reason', ['reference' => $payin->reference, 'reason' => $reason]),
            Alerts::link('branch.deposits.index', ['txn' => $payin->id]),
        );
    }

    /**
     * The branch can't find the money: Admin should move the deposit to the
     * branch / account it really reached.
     */
    public function depositNotInBank(Transaction $payin, User $reporter): void
    {
        $branch = Branch::query()->whereKey($payin->branch_id)->value('code');

        $this->alerts->send(
            Alert::DepositNotInBank,
            $this->alerts->recipients(Permission::PayinsApprove, UserType::Admin),
            __('Deposit :reference isn’t in branch :branch’s bank', ['reference' => $payin->reference, 'branch' => $branch ?? '—']),
            __(':name says ₹:amount (customer UTR :utr) didn’t reach their account. Move it to the branch it was paid to in Manual Deposit. Note: :note', [
                'name' => $reporter->username ?? $reporter->name,
                'amount' => $this->money($payin->amount),
                'utr' => $payin->customer_utr_normalized ?? '—',
                'note' => $payin->status_note ?? '—',
            ]),
            Alerts::link('admin.deposits.index', ['tab' => 'hold', 'txn' => $payin->id]),
        );
    }

    public function webhookFailed(WebhookEvent $event): void
    {
        $reference = Transaction::query()->whereKey($event->transaction_id)->value('reference');

        $this->alerts->send(
            Alert::WebhookFailed,
            $this->alerts->recipients(Permission::WebhooksView, UserType::Partner, $event->partner_id),
            __('Webhook failed: :type', ['type' => $event->event_type]),
            __('We couldn’t deliver :type for :reference to :url after all retries. Fix your endpoint, then resend it from the transaction.', ['type' => $event->event_type, 'reference' => $reference ?? '—', 'url' => $event->url]),
            Alerts::link('partner.payins.index'),
        );
    }

    public function adjustmentRequested(Adjustment $adjustment, User $requester): void
    {
        $this->alerts->send(
            Alert::AdjustmentRequested,
            $this->alerts->recipients(Permission::AdjustmentsApprove, UserType::Admin, except: $requester),
            __('Adjustment :reference awaits approval', ['reference' => $adjustment->reference]),
            __(':name asked for ₹:amount (:type): :reason', ['name' => $requester->name, 'amount' => $this->money(abs($adjustment->amount)), 'type' => $adjustment->type, 'reason' => $adjustment->reason]),
            Alerts::link('admin.adjustments.index'),
        );
    }

    public function adjustmentDecided(Adjustment $adjustment): void
    {
        $requester = User::query()->find($adjustment->requested_by);

        if ($requester !== null) {
            $this->alerts->send(
                Alert::AdjustmentDecided,
                collect([$requester]),
                __('Adjustment :reference :status', ['reference' => $adjustment->reference, 'status' => $adjustment->status]),
                $adjustment->decision_note ?? __('No note.'),
                Alerts::link('admin.adjustments.index', ['tab' => $adjustment->status]),
            );
        }
    }

    public function settlementCalculated(Settlement $settlement): void
    {
        if ($settlement->net_amount === 0) {
            return;
        }

        $partner = $settlement->party_type === 'partner';
        $toParty = $settlement->direction === 'platform_to_party';

        $this->alerts->send(
            Alert::SettlementCalculated,
            $this->alerts->recipients(Permission::SettlementsView, $partner ? UserType::Partner : UserType::Branch, $settlement->partyId()),
            __('Settlement :reference: ₹:amount', ['reference' => $settlement->reference, 'amount' => $this->money($settlement->net_amount)]),
            $toParty ? __('PayGate owes you this amount; payments are recorded in Settlements.') : __('You owe PayGate this amount; please settle it as agreed.'),
            Alerts::link(($partner ? 'partner' : 'branch').'.settlements.index', ['settlement' => $settlement->id]),
        );
    }

    public function accountSaved(PaymentAccount $account): void
    {
        $waiting = $account->verification === AccountVerification::Pending
            && ($account->wasChanged('verification') || $account->wasRecentlyCreated);

        if ($waiting) {
            $this->alerts->send(
                Alert::AccountVerification,
                $this->alerts->recipients(Permission::AccountsVerify, UserType::Admin),
                __('Bank account to verify: :label', ['label' => $account->label]),
                __('Branch :branch added or changed an account; it can’t receive customers until verified.', ['branch' => Branch::query()->whereKey($account->branch_id)->value('code')]),
                Alerts::link('admin.accounts.index'),
            );
        }
    }

    public function accountReviewed(PaymentAccount $account): void
    {
        $verified = $account->verification === AccountVerification::Verified;

        $this->alerts->send(
            Alert::AccountReviewed,
            $this->alerts->recipients(Permission::AccountsView, UserType::Branch, $account->branch_id),
            $verified ? __(':label verified', ['label' => $account->label]) : __(':label rejected', ['label' => $account->label]),
            $verified ? __('Activate it to start receiving customers.') : __('Reason: :reason. Edit the account to resubmit it.', ['reason' => $account->rejected_reason]),
            Alerts::link('branch.accounts.index'),
        );
    }

    public function reversal(TransactionReversal $reversal, Transaction $txn): void
    {
        $what = match ($reversal->kind) {
            'chargeback' => __('Chargeback on :reference', ['reference' => $txn->reference]),
            'refund' => __('Deposit :reference refunded', ['reference' => $txn->reference]),
            default => __('Payout :reference returned', ['reference' => $txn->reference]),
        };
        $body = __('₹:amount · :reason', ['amount' => $this->money($reversal->amount), 'reason' => $reversal->reason]);
        $view = $txn->isPayin() ? Permission::PayinsView : Permission::PayoutsView;

        $this->alerts->send(Alert::Reversal, $this->alerts->recipients($view, UserType::Partner, $txn->partner_id), $what, $body, Alerts::link($txn->isPayin() ? 'partner.payins.index' : 'partner.payouts.index', ['txn' => $txn->id]));
        $this->alerts->send(Alert::Reversal, $this->alerts->recipients($view, UserType::Branch, $txn->branch_id), $what, $body, Alerts::link($txn->isPayin() ? 'branch.payins.index' : 'branch.payouts.history', ['txn' => $txn->id]));
    }

    /**
     * A branch raised a chargeback: the admins who can approve it.
     */
    public function chargebackRequested(Chargeback $chargeback, Transaction $txn, User $requester): void
    {
        $this->alerts->send(
            Alert::ChargebackRequested,
            $this->alerts->recipients(Permission::ReversalsApprove, UserType::Admin, except: $requester),
            __('Chargeback :reference awaits approval', ['reference' => $chargeback->reference]),
            __(':name raised ₹:amount on :txn', ['name' => $requester->name, 'amount' => $this->money($chargeback->amount), 'txn' => $txn->reference]),
            Alerts::link('admin.chargebacks.index'),
        );
    }

    /**
     * Approved: the partner (who bears it) and the branch. Rejected: the
     * branch, with the admin's note.
     */
    public function chargebackDecided(Chargeback $chargeback, Transaction $txn): void
    {
        $approved = $chargeback->status === 'approved';
        $title = $approved
            ? __('Chargeback on :reference', ['reference' => $txn->reference])
            : __('Chargeback on :reference rejected', ['reference' => $txn->reference]);
        $body = __('₹:amount · :note', ['amount' => $this->money($chargeback->amount), 'note' => $chargeback->decision_note ?? __('No note.')]);

        if ($approved) {
            $this->alerts->send(Alert::Reversal, $this->alerts->recipients(Permission::PayinsView, UserType::Partner, $txn->partner_id), $title, $body, Alerts::link('partner.chargebacks.index', ['txn' => $txn->id]));
        }

        $this->alerts->send(Alert::Reversal, $this->alerts->recipients(Permission::PayinsView, UserType::Branch, $txn->branch_id), $title, $body, Alerts::link('branch.chargebacks.index', ['txn' => $txn->id]));
    }

    /**
     * A partner (or Admin for it) says a paid payout wasn't received: the
     * branch, to check its bank and confirm, and the admins who can.
     */
    public function payoutReturnRequested(PayoutReturn $return, Transaction $txn, User $requester): void
    {
        $title = __('Payout :reference not received', ['reference' => $txn->reference]);
        $body = __(':name raised :return on ₹:amount · :reason', ['name' => $requester->name, 'return' => $return->reference, 'amount' => $this->money($txn->amount), 'reason' => $return->reason ?? __('No note.')]);

        $this->alerts->send(Alert::PayoutReturnRequested, $this->alerts->recipients(Permission::ReversalsCreate, UserType::Branch, $txn->branch_id), $title, $body, Alerts::link('branch.payout-returns.index', ['txn' => $txn->id]));
        $this->alerts->send(Alert::PayoutReturnRequested, $this->alerts->recipients(Permission::ReversalsApprove, UserType::Admin, except: $requester), $title, $body, Alerts::link('admin.payout-returns.index', ['txn' => $txn->id]));
    }

    /**
     * Confirmed or rejected: the partner and the branch.
     */
    public function payoutReturnDecided(PayoutReturn $return, Transaction $txn): void
    {
        $approved = $return->status === 'approved';
        $title = $approved
            ? __('Payout :reference returned: ₹:amount', ['reference' => $txn->reference, 'amount' => $this->money((int) $return->amount)])
            : __('Return on :reference rejected', ['reference' => $txn->reference]);
        $body = $approved
            ? ($return->utr === null ? __('Credited back to your balance today.') : __('Credited back to your balance today. UTR :utr', ['utr' => $return->utr]))
            : __('The branch says the money didn’t come back · :note', ['note' => $return->decision_note ?? __('No note.')]);

        $this->alerts->send(Alert::Reversal, $this->alerts->recipients(Permission::PayoutsView, UserType::Partner, $txn->partner_id), $title, $body, Alerts::link('partner.payout-returns.index', ['txn' => $txn->id]));
        $this->alerts->send(Alert::Reversal, $this->alerts->recipients(Permission::PayoutsView, UserType::Branch, $txn->branch_id), $title, $body, Alerts::link('branch.payout-returns.index', ['txn' => $txn->id]));
    }

    /**
     * After an import or a manual line: bank lines now in the unsettled queue.
     */
    public function unsettledLines(string $branchId, int $count, ?User $actor): void
    {
        if ($count === 0) {
            return;
        }

        $this->alerts->send(
            Alert::UnsettledLines,
            $this->alerts->recipients(Permission::ReconciliationResolve, UserType::Branch, $branchId, $actor),
            trans_choice(':count bank line needs attention|:count bank lines need attention', $count, ['count' => $count]),
            __('They didn’t match a transaction exactly; see Deposit Unsettled.'),
            Alerts::link('branch.cases.index'),
        );
    }

    /**
     * Money arrived for a closed deposit: only Admin can approve it late.
     */
    public function latePayment(ReconciliationCase $case): void
    {
        $this->alerts->send(
            Alert::UnsettledLines,
            $this->alerts->recipients(Permission::ReconciliationResolve, UserType::Admin),
            __('Late payment: case :reference', ['reference' => $case->reference]),
            (string) $case->notes,
            Alerts::link('admin.cases.index', ['case' => $case->id]),
        );
    }

    /**
     * Scheduled: deposits the customer says they paid that have waited
     * longer than the Global Settings threshold. One alert per branch, and
     * each deposit is reported once.
     *
     * @return int deposits reported
     */
    public function depositsWaiting(): int
    {
        $minutes = $this->settings->depositWaitMinutes();
        $late = Transaction::query()
            ->where('direction', 'payin')
            ->whereIn('status', ['payment_submitted', 'payment_detected', 'under_review'])
            ->where('submitted_at', '<', now()->subMinutes($minutes))
            ->whereDoesntHave('events', fn ($query) => $query->where('event', 'waiting_alerted'))
            ->get(['id', 'reference', 'branch_id', 'status', 'amount']);

        foreach ($late->groupBy('branch_id') as $branchId => $payins) {
            $this->alerts->send(
                Alert::DepositsWaiting,
                $this->alerts->recipients(Permission::PayinsApprove, UserType::Branch, (string) $branchId),
                trans_choice(':count deposit waiting over :minutes minutes|:count deposits waiting over :minutes minutes', $payins->count(), ['count' => $payins->count(), 'minutes' => $minutes]),
                __('Customers are waiting for their deposits to be confirmed: :references.', ['references' => $payins->pluck('reference')->take(5)->join(', ')]),
                Alerts::link('branch.deposits.index'),
            );

            foreach ($payins as $payin) {
                TransactionEvent::record($payin, 'waiting_alerted', $payin->status, $payin->status, 'system', null, null, ['minutes' => $minutes]);
            }
        }

        return $late->count();
    }

    /**
     * Payouts partly paid with no transfer for a day (decided 2026-10-23):
     * the customer has only part of the money. The branch that pays it and
     * the admins who process payouts; once per payout until another
     * transfer is recorded.
     */
    public function payoutsPartPaid(): int
    {
        $since = now()->subHours(self::PART_PAID_HOURS);
        $stuck = Transaction::query()
            ->where(['direction' => 'payout', 'status' => 'processing'])
            ->whereHas('payoutPayments')
            ->with('payoutPayments')
            ->get()
            ->filter(function (Transaction $payout) use ($since) {
                $last = $payout->payoutPayments->max('created_at');

                return $last !== null && $last->lt($since)
                    && ! $payout->events()->where('event', 'part_paid_alerted')->where('created_at', '>', $last)->exists();
            });

        foreach ($stuck->groupBy('branch_id') as $branchId => $payouts) {
            $title = trans_choice(':count payout only partly paid for over :hours hours|:count payouts only partly paid for over :hours hours', $payouts->count(), ['count' => $payouts->count(), 'hours' => self::PART_PAID_HOURS]);
            $body = __('Customers have received only part of their money: :list.', ['list' => $payouts->take(5)->map(fn (Transaction $payout) => __(':reference ₹:left left', [
                'reference' => $payout->reference,
                'left' => $this->money($payout->amount - (int) $payout->payoutPayments->sum('amount')),
            ]))->join(', ')]);

            $this->alerts->send(Alert::PayoutPartPaid, $this->alerts->recipients(Permission::PayoutsProcess, UserType::Branch, (string) $branchId), $title, $body, Alerts::link('branch.payouts.index'));
            $this->alerts->send(Alert::PayoutPartPaid, $this->alerts->recipients(Permission::PayoutsProcess, UserType::Admin), $title, $body, Alerts::link('admin.payouts.index'));

            foreach ($payouts as $payout) {
                TransactionEvent::record($payout, 'part_paid_alerted', $payout->status, $payout->status, 'system', null, null, ['hours' => self::PART_PAID_HOURS]);
            }
        }

        return $stuck->count();
    }

    private function money(int $paise): string
    {
        return number_format($paise / 100, 2);
    }
}
