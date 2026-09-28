<?php

namespace App\Domain\Notification;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Notification\Enums\Alert;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Settings;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Settlement\Models\Adjustment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Models\TransactionEvent;
use App\Domain\Transaction\Models\TransactionReversal;
use App\Domain\Webhook\Models\WebhookEvent;

/**
 * Who hears about what (G-47). Each method picks the people who can act
 * (portal, organisation, permission) and words the alert. Called from the
 * actions where the event happens; everything is queued after commit.
 */
class AlertDispatcher
{
    public function __construct(private Alerts $alerts, private Settings $settings) {}

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
        if ($account->status === AccountStatus::VerificationPending && $account->wasChanged('status') || ($account->wasRecentlyCreated && $account->status === AccountStatus::VerificationPending)) {
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
        $verified = $account->status === AccountStatus::Verified;

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

    private function money(int $paise): string
    {
        return number_format($paise / 100, 2);
    }
}
