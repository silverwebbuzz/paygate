<?php

namespace App\Domain\Partner\Actions;

use App\Domain\Commission\Actions\SetCommissionRate;
use App\Domain\Commission\Enums\Direction;
use App\Domain\Commission\RateBook;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use App\Domain\Network\Actions\SyncMappings;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a partner from the Admin wizard in one transaction:
 * profile and settings, allowed IPs, commission rates and branch mapping.
 * A section passed as null is left unchanged (e.g. when the admin lacks the
 * permission for it). A new partner starts as a draft with an API key.
 */
class ConfigurePartner
{
    /**
     * Fields that can't change once the partner has left draft: partners use
     * the code in API calls and reports.
     */
    private const LOCKED_AFTER_DRAFT = ['code'];

    public function __construct(
        private SyncIpRules $syncIpRules,
        private SyncMappings $syncBranches,
        private SetCommissionRate $setRate,
        private RateBook $rates,
        private IssueApiKey $issueApiKey,
        private ChangePartnerStatus $changeStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  partner columns (amounts in paise)
     * @param  list<string>|null  $ipAddresses
     * @param  array<string, string>|null  $rates  direction value => rate percent
     * @param  list<string>|null  $branchIds
     * @param  bool  $activate  also take a new or draft partner live (all or nothing)
     * @return array{partner: Partner, secret: string|null, negative_margins: array<string, mixed>}
     */
    public function handle(User $actor, ?Partner $partner, array $attributes, ?array $ipAddresses, ?array $rates, ?array $branchIds, bool $activate = false): array
    {
        return DB::transaction(function () use ($actor, $partner, $attributes, $ipAddresses, $rates, $branchIds, $activate) {
            $secret = null;

            if ($partner === null) {
                $partner = Partner::create([...$attributes, 'status' => OrganisationStatus::Draft]);
                AuditLog::record('partner.created', $partner, [], $partner->only(array_keys($attributes)), $actor);
                $secret = $this->issueApiKey->handle($actor, $partner)['secret'];
            } else {
                $this->update($actor, $partner, $attributes);
            }

            if ($ipAddresses !== null) {
                $this->syncIpRules->handle($actor, $partner, $ipAddresses);
            }

            // Branches first, so the margin check sees the new mapping.
            if ($branchIds !== null) {
                $this->syncBranches->forPartner($actor, $partner, $branchIds);
            }

            $negative = [];

            foreach ($rates ?? [] as $direction => $rate) {
                $direction = Direction::from($direction);
                $problems = $this->rates->negativeMargins($partner, $direction, $rate);
                $this->setRate->handle($actor, 'partner', $partner, 'partner', $direction, $rate, $problems);

                if ($problems !== []) {
                    $negative[$direction->value] = $problems;
                }
            }

            if ($activate && $partner->status !== OrganisationStatus::Active) {
                $this->changeStatus->handle($actor, $partner, OrganisationStatus::Active, __('Activated from the partner wizard.'));
            }

            return ['partner' => $partner, 'secret' => $secret, 'negative_margins' => $negative];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(User $actor, Partner $partner, array $attributes): void
    {
        $partner->fill($attributes);

        if ($partner->status !== OrganisationStatus::Draft && $partner->isDirty(self::LOCKED_AFTER_DRAFT)) {
            throw ValidationException::withMessages(['code' => __('The partner code can’t change after the partner went live.')]);
        }

        $changed = array_keys($partner->getDirty());

        if ($changed === []) {
            return;
        }

        $old = array_intersect_key($partner->getOriginal(), array_flip($changed));
        $partner->save();

        AuditLog::record('partner.updated', $partner, $old, $partner->only($changed), $actor);
    }
}
