<?php

namespace App\Domain\Platform;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Global settings stored in the `settings` table, with defaults in code.
 * Every change is audited. Cached briefly; a change clears the cache.
 */
class Settings
{
    /** When the daily settlement day ends (decided 2026-09-28, G-09: set by Admin). */
    public const SETTLEMENT_CUTOFF = 'settlement.cutoff';

    /** Alert branches when a customer's deposit waits longer than this (G-47). */
    public const DEPOSIT_WAIT_MINUTES = 'alerts.deposit_wait_minutes';

    /**
     * Approve a deposit by itself when a branch's bank line shows its UTR and
     * exact amount (decided 2026-10-21, replaces "the branch always approves").
     */
    public const AUTO_APPROVE_DEPOSITS = 'deposits.auto_approve';

    /**
     * Alert Admin when a deposit approved by hand has no bank statement line
     * this many hours later (0 = off): the money was never shown arriving.
     */
    public const UNMATCHED_APPROVAL_HOURS = 'deposits.unmatched_alert_hours';

    /** Support contact on the customer payment page (G-48). */
    public const CHECKOUT_SUPPORT = 'checkout.support';

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        self::SETTLEMENT_CUTOFF => ['timezone' => 'Asia/Kolkata', 'time' => '00:00'],
        self::DEPOSIT_WAIT_MINUTES => 30,
        self::CHECKOUT_SUPPORT => ['email' => null, 'phone' => null],
        self::AUTO_APPROVE_DEPOSITS => true,
        self::UNMATCHED_APPROVAL_HOURS => 24,
    ];

    public function get(string $key): mixed
    {
        return Cache::remember('setting:'.$key, 60, fn () => Setting::query()->find($key)->value ?? self::DEFAULTS[$key] ?? null);
    }

    public function set(string $key, mixed $value, User $actor): void
    {
        $old = $this->get($key);

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor->id, 'updated_at' => now()]);
        Cache::forget('setting:'.$key);

        AuditLog::record('setting.updated', null, ['key' => $key, 'value' => $old], ['key' => $key, 'value' => $value], $actor);
    }

    /**
     * @return array{timezone: string, time: string}
     */
    public function settlementCutoff(): array
    {
        /** @var array{timezone?: string, time?: string} $value */
        $value = (array) $this->get(self::SETTLEMENT_CUTOFF);

        return [
            'timezone' => $value['timezone'] ?? 'Asia/Kolkata',
            'time' => $value['time'] ?? '00:00',
        ];
    }

    public function depositWaitMinutes(): int
    {
        return max(5, (int) $this->get(self::DEPOSIT_WAIT_MINUTES));
    }

    public function autoApproveDeposits(): bool
    {
        return (bool) $this->get(self::AUTO_APPROVE_DEPOSITS);
    }

    public function unmatchedApprovalHours(): int
    {
        return max(0, (int) $this->get(self::UNMATCHED_APPROVAL_HOURS));
    }

    /**
     * @return array{email: string|null, phone: string|null}
     */
    public function checkoutSupport(): array
    {
        $value = (array) $this->get(self::CHECKOUT_SUPPORT);

        return ['email' => $value['email'] ?? null, 'phone' => $value['phone'] ?? null];
    }

    /**
     * The most recent daily cut-off at or before `$at` (e.g. today 00:00 IST).
     */
    public function lastCutoff(?CarbonImmutable $at = null): CarbonImmutable
    {
        ['timezone' => $zone, 'time' => $time] = $this->settlementCutoff();
        [$hour, $minute] = array_map('intval', explode(':', $time));

        $now = ($at ?? CarbonImmutable::now())->setTimezone($zone);
        $cutoff = $now->setTime($hour, $minute);

        return ($cutoff->greaterThan($now) ? $cutoff->subDay() : $cutoff)->utc();
    }
}
