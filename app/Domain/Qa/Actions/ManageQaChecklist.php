<?php

namespace App\Domain\Qa\Actions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\PaymentSession\Models\PaymentSession;
use App\Domain\Payout\Actions\CreatePayout;
use App\Domain\Qa\Checklist;
use App\Domain\Qa\Jobs\RunQaChecks;
use App\Domain\Qa\Models\QaResult;
use App\Domain\Transaction\Actions\CreatePayin;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin › QA Checklist actions: mark an item, queue its automated tests,
 * and create test pay-ins / payouts through the same code the Partner API
 * uses (so testers don't need to sign API calls by hand).
 */
class ManageQaChecklist
{
    public function __construct(private CreatePayin $createPayin, private CreatePayout $createPayout) {}

    /**
     * @param  string|null  $status  pass / fail, or null to clear
     */
    public function mark(User $actor, string $key, ?string $status, ?string $note): QaResult
    {
        $this->item($key);

        $result = QaResult::query()->findOrNew($key);
        $result->check_key = $key;
        $result->fill([
            'manual_status' => $status,
            'manual_note' => $status === null ? null : $note,
            'tested_by' => $status === null ? null : $actor->id,
            'tested_at' => $status === null ? null : now(),
        ])->save();

        return $result;
    }

    /**
     * Queues the tests of one item, or of every item (the whole suite once).
     *
     * @return int how many items were queued
     */
    public function run(User $actor, ?string $key): int
    {
        $items = $key === null ? Checklist::items() : [$key => $this->item($key)];
        $keys = [];

        foreach ($items as $itemKey => $item) {
            $result = QaResult::query()->find($itemKey);

            if ($item['tests'] === [] || $result?->isRunning() === true) {
                continue;
            }

            $keys[] = $itemKey;
        }

        if ($keys === []) {
            throw ValidationException::withMessages(['run' => __('These tests are already running.')]);
        }

        foreach ($keys as $itemKey) {
            $result = QaResult::query()->findOrNew($itemKey);
            $result->check_key = $itemKey;
            $result->fill([
                'auto_status' => 'queued',
                'auto_requested_by' => $actor->id,
                'auto_requested_at' => now(),
            ])->save();
        }

        RunQaChecks::dispatch($keys, $key === null);

        return count($keys);
    }

    /**
     * @return array{reference: string, url: string}
     */
    public function createPayin(Partner $partner, int $amount): array
    {
        try {
            $created = $this->createPayin->handle($partner, [
                'order_id' => 'QA-'.Str::upper(Str::random(10)),
                'amount' => $amount,
                'customer' => ['id' => 'qa-customer', 'name' => 'QA Tester'],
                'metadata' => ['qa' => true],
            ]);
        } catch (ApiException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return [
            'reference' => $created['payin']->reference,
            'url' => route('pay.checkout.show', PaymentSession::tokenFor($created['session']->id)),
        ];
    }

    /**
     * @return array{reference: string, branch: string|null}
     */
    public function createPayout(Partner $partner, int $amount): array
    {
        try {
            $created = $this->createPayout->handle($partner, [
                'order_id' => 'QA-'.Str::upper(Str::random(10)),
                'amount' => $amount,
                'customer' => ['id' => 'qa-customer', 'name' => 'QA Tester'],
                'beneficiary' => ['type' => 'bank', 'name' => 'QA Tester', 'account_number' => '50100482716640', 'ifsc' => 'HDFC0001203'],
                'metadata' => ['qa' => true],
            ]);
        } catch (ApiException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        /** @var Transaction $payout */
        $payout = $created['payout']->load('branch');

        return ['reference' => $payout->reference, 'branch' => $payout->branch?->code];
    }

    /**
     * @return array{key: string, name: string, url: string|null, description: string, steps: list<string>, login: list<string>, tests: list<string>}
     */
    private function item(string $key): array
    {
        return Checklist::items()[$key] ?? throw ValidationException::withMessages(['key' => __('Unknown checklist item.')]);
    }
}
