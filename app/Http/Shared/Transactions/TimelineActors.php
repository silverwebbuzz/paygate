<?php

namespace App\Http\Shared\Transactions;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Transaction\Models\TransactionEvent;
use Illuminate\Support\Collection;

/**
 * Who did each step of a transaction's timeline, as the viewer may see it
 * (client request 2026-11-18: "by user" said nothing). Loads the people,
 * API keys, branches and accounts of all events at once.
 *
 * - Admin sees everyone: name, role and organisation.
 * - A branch sees the names of its own people; Admin is "PayGate Admin",
 *   another branch "Another branch", the partner "Partner".
 * - A partner sees the names of its own people and API keys; anyone at
 *   PayGate or a branch is "PayGate".
 * - A step taken automatically (a bank line matched) says so, and Admin /
 *   the same branch see whose bank line triggered it.
 */
final class TimelineActors
{
    /** @var Collection<string, User> */
    private Collection $users;

    /** @var Collection<string, PartnerApiKey> */
    private Collection $keys;

    /** @var Collection<string, Branch> */
    private Collection $branches;

    /** @var Collection<string, PaymentAccount> */
    private Collection $accounts;

    private const REASONS = [
        'moved' => 'moved to another account',
        'method_changed' => 'the customer switched payment method',
        'customer_changed_account' => 'the customer asked for other payment details',
        'late_payment' => 'the money arrived late',
        'expired' => 'the payment time ran out',
        'rejected' => 'declined',
        'cancelled' => 'cancelled',
        'success' => 'approved',
    ];

    /**
     * @param  Collection<int, TransactionEvent>  $events
     */
    public function __construct(Collection $events, private User $viewer)
    {
        $ids = fn (string $type) => $events->where('actor_type', $type)->pluck('actor_id')->filter()->unique()->values();

        $this->users = User::query()->whereIn('id', $ids('user'))->with(['role', 'branch', 'partner'])->get()->keyBy('id');
        $this->keys = PartnerApiKey::query()->whereIn('id', $ids('partner_api'))->get()->keyBy('id');

        $branchIds = $events->flatMap(fn (TransactionEvent $event) => [$event->data['branch_id'] ?? null, $event->data['from_branch_id'] ?? null])->filter()->unique()->values();
        $accountIds = $events->flatMap(fn (TransactionEvent $event) => [$event->data['account_id'] ?? null, $event->data['from_account_id'] ?? null])->filter()->unique()->values();
        $this->branches = Branch::query()->whereIn('id', $branchIds)->get(['id', 'name', 'code'])->keyBy('id');
        $this->accounts = PaymentAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');
    }

    /**
     * @return array{name: string, role: ?string, org: ?string, auto: bool}
     */
    public function by(TransactionEvent $event): array
    {
        $auto = (bool) ($event->data['auto'] ?? false);

        $who = match ($event->actor_type) {
            'user' => $this->user($event->actor_id),
            'partner_api' => $this->apiKey($event->actor_id),
            'customer' => ['name' => self::t('Customer'), 'role' => null, 'org' => null],
            default => ['name' => self::t('PayGate (automatic)'), 'role' => null, 'org' => null],
        };

        // Automatic step caused by a person's bank line: name the system,
        // and the person only to those who may see them.
        if ($auto && $event->actor_type === 'user') {
            $person = $this->visibleUser($event->actor_id);

            return [
                'name' => self::t('PayGate (automatic)'),
                'role' => null,
                'org' => $person === null ? null : self::t('from the bank line entered by :name', ['name' => $person]),
                'auto' => true,
            ];
        }

        return [...$who, 'auto' => $auto];
    }

    /**
     * Where a deposit was assigned / moved: account and branch, as far as
     * the viewer may see them.
     */
    public function detail(TransactionEvent $event): ?string
    {
        if (! in_array($event->event, ['allocated', 'moved', 'reassigned'], true)) {
            return null;
        }

        $to = $this->place($event->data['branch_id'] ?? null, $event->data['account_id'] ?? null);
        $from = $this->place($event->data['from_branch_id'] ?? null, $event->data['from_account_id'] ?? null);

        return match (true) {
            $from !== null && $to !== null => self::t('from :from to :to', ['from' => $from, 'to' => $to]),
            $to !== null => self::t('to :to', ['to' => $to]),
            default => null,
        };
    }

    /**
     * A system reason code ("moved") in words; people's notes unchanged.
     */
    public function reason(TransactionEvent $event): ?string
    {
        $reason = $event->reason;

        if ($reason === null || preg_match('/^[a-z_]+$/', $reason) !== 1) {
            return $reason;
        }

        return self::t(self::REASONS[$reason] ?? str_replace('_', ' ', $reason));
    }

    /**
     * @return array{name: string, role: ?string, org: ?string}
     */
    private function user(?string $id): array
    {
        $user = $id === null ? null : $this->users->get($id);

        if ($user === null) {
            return ['name' => self::t('A user (removed)'), 'role' => null, 'org' => null];
        }

        $org = match ($user->type) {
            UserType::Admin => self::t('PayGate Admin'),
            UserType::Branch => $user->branch?->name,
            UserType::Partner => $user->partner?->name,
        };

        if ($this->sees($user)) {
            return ['name' => $user->name, 'role' => $user->role->name ?? null, 'org' => $org];
        }

        return ['name' => match (true) {
            $this->viewer->isType(UserType::Partner) => self::t('PayGate'),
            $user->isType(UserType::Admin) => self::t('PayGate Admin'),
            $user->isType(UserType::Partner) => self::t('Partner'),
            default => self::t('Another branch'),
        }, 'role' => null, 'org' => null];
    }

    /**
     * @return array{name: string, role: ?string, org: ?string}
     */
    private function apiKey(?string $id): array
    {
        $key = $id === null ? null : $this->keys->get($id);

        return match (true) {
            $this->viewer->isType(UserType::Branch) => ['name' => self::t('Partner'), 'role' => self::t('API'), 'org' => null],
            $key === null => ['name' => self::t('Partner API'), 'role' => null, 'org' => null],
            default => [
                'name' => $this->viewer->isType(UserType::Partner) ? self::t('Your server') : (string) (Partner::query()->whereKey($key->partner_id)->value('name') ?? self::t('Partner')),
                'role' => self::t('API key :key', ['key' => $key->key_id]),
                'org' => null,
            ],
        };
    }

    private function visibleUser(?string $id): ?string
    {
        $user = $id === null ? null : $this->users->get($id);

        return $user !== null && $this->sees($user) ? $user->name : null;
    }

    /** Whether the viewer may see this person's name. */
    private function sees(User $user): bool
    {
        return match ($this->viewer->type) {
            UserType::Admin => true,
            UserType::Branch => $user->isType(UserType::Branch) && $user->branch_id === $this->viewer->branch_id,
            UserType::Partner => $user->isType(UserType::Partner) && $user->partner_id === $this->viewer->partner_id,
        };
    }

    private function place(mixed $branchId, mixed $accountId): ?string
    {
        if (! is_string($branchId) && ! is_string($accountId)) {
            return null;
        }

        $branch = is_string($branchId) ? $this->branches->get($branchId) : null;
        $account = is_string($accountId) ? $this->accounts->get($accountId) : null;

        return match ($this->viewer->type) {
            UserType::Admin => implode(' · ', array_filter([$branch?->name, $account?->label])) ?: null,
            UserType::Branch => $branchId === $this->viewer->branch_id ? ($account === null ? self::t('your branch') : $account->label) : self::t('another branch'),
            UserType::Partner => null,
        };
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
