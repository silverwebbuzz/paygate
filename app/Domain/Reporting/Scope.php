<?php

namespace App\Domain\Reporting;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Whose figures a dashboard or report shows: everything (Admin), one
 * partner, or one branch. Partners never see branch figures and branches
 * never see a partner's commission or the margin; reports and dashboards
 * ask `sees()` before adding such a column.
 */
final class Scope
{
    private function __construct(
        public readonly UserType $type,
        public readonly ?string $partnerId,
        public readonly ?string $branchId,
    ) {}

    public static function of(User $user): self
    {
        return new self($user->type, $user->partner_id, $user->branch_id);
    }

    public static function admin(): self
    {
        return new self(UserType::Admin, null, null);
    }

    public function isAdmin(): bool
    {
        return $this->type === UserType::Admin;
    }

    /**
     * @param  'partner_commission'|'branch_commission'|'margin'|'branch'|'partner'  $what
     */
    public function sees(string $what): bool
    {
        return match ($this->type) {
            UserType::Admin => true,
            UserType::Partner => in_array($what, ['partner_commission', 'partner'], true),
            UserType::Branch => in_array($what, ['branch_commission', 'branch', 'partner'], true),
        };
    }

    /**
     * Limits a query on a table with partner_id / branch_id columns.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function apply(Builder $query, string $table = ''): Builder
    {
        $prefix = $table === '' ? '' : $table.'.';

        return match ($this->type) {
            UserType::Admin => $query,
            UserType::Partner => $query->where($prefix.'partner_id', $this->partnerId),
            UserType::Branch => $query->where($prefix.'branch_id', $this->branchId),
        };
    }

    public function cacheKey(): string
    {
        return $this->type->value.':'.($this->partnerId ?? $this->branchId ?? 'all');
    }
}
