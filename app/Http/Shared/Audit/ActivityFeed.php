<?php

namespace App\Http\Shared\Audit;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Database\Eloquent\Builder;

class ActivityFeed
{
    /**
     * @return list<array{action: string, summary: string, changes: list<string>, actor: string, at: string, reason: string|null}>
     */
    public static function forPartner(Partner $partner): array
    {
        return self::rows(AuditLog::query()->where(function (Builder $query) use ($partner) {
            $query->where(fn (Builder $query) => $query->where('subject_type', 'partner')->where('subject_id', $partner->id))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'mapping')->whereIn('subject_id', PartnerBranchMapping::query()->where('partner_id', $partner->id)->select('id')));
        }));
    }

    /**
     * @return list<array{action: string, summary: string, changes: list<string>, actor: string, at: string, reason: string|null}>
     */
    public static function forBranch(Branch $branch): array
    {
        return self::rows(AuditLog::query()->where(function (Builder $query) use ($branch) {
            $query->where(fn (Builder $query) => $query->where('subject_type', 'branch')->where('subject_id', $branch->id))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'payment_account')->whereIn('subject_id', PaymentAccount::query()->where('branch_id', $branch->id)->select('id')))
                ->orWhere(fn (Builder $query) => $query->where('subject_type', 'mapping')->whereIn('subject_id', PartnerBranchMapping::query()->where('branch_id', $branch->id)->select('id')));
        }));
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return list<array{action: string, summary: string, changes: list<string>, actor: string, at: string, reason: string|null}>
     */
    private static function rows(Builder $query): array
    {
        return $query
            ->with(['actor', 'subject' => fn ($morph) => $morph->morphWith([
                PartnerBranchMapping::class => ['partner', 'branch'],
            ])])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(function (AuditLog $log) {
                $actor = $log->actor;
                $who = $actor === null ? 'System' : $actor->name.($actor->username ? ' · '.$actor->username : '');
                $summary = $log->summary();
                $subject = $log->subject;

                if ($subject instanceof PaymentAccount) {
                    $summary .= ' · '.$subject->label;
                }

                if ($subject instanceof PartnerBranchMapping && $subject->relationLoaded('partner') && $subject->relationLoaded('branch')) {
                    $summary .= ' · '.$subject->partner->code.' ↔ '.$subject->branch->code;
                }

                return [
                    'action' => $log->action,
                    'summary' => $summary,
                    'changes' => $log->changeLines(),
                    'actor' => $who,
                    'at' => $log->created_at->toIso8601String(),
                    'reason' => is_string($log->new_values['reason'] ?? null) ? $log->new_values['reason'] : null,
                ];
            })
            ->all();
    }
}
