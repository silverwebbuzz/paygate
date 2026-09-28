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

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        self::SETTLEMENT_CUTOFF => ['timezone' => 'Asia/Kolkata', 'time' => '00:00'],
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
