<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A reporting period, [from, to), from the design's range buttons (Today,
 * Yesterday, 1h, 24h, 7d, 30d, This month, Prev month, Custom). Days are
 * business days (config app.business_timezone). Charts use hourly buckets
 * up to two days, daily buckets beyond.
 */
final class Period
{
    public const RANGES = ['today', 'yesterday', '1h', '24h', '7d', '30d', 'this_month', 'prev_month', 'custom'];

    private function __construct(
        public readonly string $range,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function resolve(?string $range, ?string $fromDate = null, ?string $toDate = null, ?CarbonImmutable $now = null): self
    {
        $zone = (string) config('app.business_timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($zone);
        $range = in_array($range, self::RANGES, true) ? $range : 'today';

        [$from, $to] = match ($range) {
            'yesterday' => [$now->subDay()->startOfDay(), $now->startOfDay()],
            '1h' => [$now->subHour(), $now],
            '24h' => [$now->subDay(), $now],
            '7d' => [$now->subDays(6)->startOfDay(), $now],
            '30d' => [$now->subDays(29)->startOfDay(), $now],
            'this_month' => [$now->startOfMonth(), $now],
            'prev_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->startOfMonth()],
            'custom' => self::custom($fromDate, $toDate, $zone),
            default => [$now->startOfDay(), $now],
        };

        return new self($range, $from->utc(), $to->utc());
    }

    /**
     * A period already resolved (e.g. stored with an export).
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, string $range = 'custom'): self
    {
        return new self($range, $from->utc(), $to->utc());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function custom(?string $from, ?string $to, string $zone): array
    {
        $valid = fn (?string $date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;

        if (! $valid($from) || ! $valid($to) || $from > $to) {
            throw ValidationException::withMessages(['from' => __('Choose a start and an end date (the start on or before the end).')]);
        }

        $start = CarbonImmutable::parse((string) $from, $zone)->startOfDay();
        $end = CarbonImmutable::parse((string) $to, $zone)->addDay()->startOfDay();

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['from' => __('Choose at most one year.')]);
        }

        return [$start, $end];
    }

    /**
     * The same length of time just before (for "▲ 12% vs previous").
     */
    public function previous(): self
    {
        $length = (int) $this->from->diffInSeconds($this->to);

        return new self($this->range, $this->from->subSeconds($length), $this->from);
    }

    public function bucket(): string
    {
        return $this->from->diffInHours($this->to) <= 48 ? 'hour' : 'day';
    }

    /**
     * @return array{range: string, from: string, to: string, label: string}
     */
    public function toArray(): array
    {
        $zone = (string) config('app.business_timezone');
        $to = $this->to->setTimezone($zone);
        $from = $this->from->setTimezone($zone);

        return [
            'range' => $this->range,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            // Inclusive dates for the date pickers.
            'label' => $from->format('d M Y, H:i').' – '.$to->format('d M Y, H:i'),
        ];
    }
}
