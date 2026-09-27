<?php

namespace App\Domain\Network\Actions;

use App\Domain\Commission\Actions\SetCommissionRate;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Commission\RatePercent;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use Illuminate\Support\Facades\DB;

/**
 * Edits one partner ↔ branch pair: on/off per direction, optional daily
 * limits for the pair, and optional pair rates that override the partner's
 * or the branch's own rate for this pair only (Req G-59).
 */
class UpdateMapping
{
    public function __construct(private SetCommissionRate $setRate, private RateBook $rates) {}

    /**
     * @param  array<string, mixed>  $attributes  status, is_*_enabled, *_daily_limit (paise)
     * @param  array<string, array<string, string|null>>|null  $overrides  side => direction => rate,
     *                                                                     '' clears the override, null leaves it
     * @return list<string> directions where the pair now loses money
     */
    public function handle(User $actor, PartnerBranchMapping $mapping, array $attributes, ?array $overrides): array
    {
        return DB::transaction(function () use ($actor, $mapping, $attributes, $overrides) {
            $mapping->fill($attributes);
            $changed = array_keys($mapping->getDirty());

            if ($changed !== []) {
                $old = array_intersect_key($mapping->getOriginal(), array_flip($changed));
                $mapping->save();
                AuditLog::record('mapping.updated', $mapping, $old, $mapping->only($changed), $actor);
            }

            foreach ($overrides ?? [] as $side => $byDirection) {
                foreach ($byDirection as $direction => $rate) {
                    if ($rate === null) {
                        continue;
                    }

                    $rate === ''
                        ? $this->setRate->clear($actor, 'mapping', $mapping, $side, Direction::from($direction))
                        : $this->setRate->handle($actor, 'mapping', $mapping, $side, Direction::from($direction), $rate);
                }
            }

            $losing = [];

            foreach (Direction::cases() as $direction) {
                $pair = $this->rates->forPair($mapping, $direction);

                if ($pair['partner'] !== null && $pair['branch'] !== null && RatePercent::compare($pair['partner'], $pair['branch']) < 0) {
                    $losing[] = $direction->value;
                }
            }

            if ($losing !== [] && $overrides !== null) {
                AuditLog::record('mapping.negative_margin', $mapping, [], ['directions' => $losing], $actor);
            }

            return $losing;
        });
    }
}
