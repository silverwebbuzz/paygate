<?php

namespace App\Http\Api\V1;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\Payout\Actions\CreatePayout;
use App\Domain\Payout\Actions\ProcessPayout;
use App\Domain\Payout\PartnerBalance;
use App\Domain\Payout\PayoutData;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Api\V1\Requests\CreatePayoutRequest;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner API v1: payouts and balance. Scoped to the calling partner.
 */
class PayoutController extends Controller
{
    public function store(CreatePayoutRequest $request, CreatePayout $create): JsonResponse
    {
        /** @var array{order_id: string, amount: int, customer: array{id: string, name?: string|null, email?: string|null, mobile?: string|null}, beneficiary: array{type: string, name: string, account_number?: string|null, ifsc?: string|null, bank_name?: string|null, upi_id?: string|null, email?: string|null, phone?: string|null}, metadata?: array<string, mixed>|null} $data */
        $data = $request->validated();
        $result = $create->handle($this->partner($request), $data, $this->key($request)->id);

        return response()->json(['data' => PayoutData::forPartner($result['payout']->load(['customer', 'beneficiary']))], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, ?string $reference = null): JsonResponse
    {
        $orderId = $request->query('order_id');

        $payout = $this->query($request)
            ->when($reference !== null, fn ($query) => $query->where('reference', $reference))
            ->when($reference === null, fn ($query) => $query->where('partner_transaction_id', is_string($orderId) ? $orderId : ''))
            ->first() ?? throw new ApiException('not_found', 'No payout with this id for your account.', 404);

        return response()->json(['data' => PayoutData::forPartner($payout)]);
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['array', 'max:100'],
            'ids.*' => ['string', 'max:30'],
            'order_ids' => ['array', 'max:100'],
            'order_ids.*' => ['string', 'max:100'],
        ]);

        $ids = array_values(array_unique($data['ids'] ?? []));
        $orderIds = array_values(array_unique($data['order_ids'] ?? []));

        if ($ids === [] && $orderIds === []) {
            throw new ApiException('validation_failed', 'Send ids and/or order_ids (up to 100 each).', 422);
        }

        $payouts = $this->query($request)->where(fn ($query) => $query->whereIn('reference', $ids)->orWhereIn('partner_transaction_id', $orderIds))->get();
        $found = $payouts->pluck('reference')->merge($payouts->pluck('partner_transaction_id'))->all();

        return response()->json([
            'data' => $payouts->map(fn (Transaction $payout) => PayoutData::forPartner($payout))->values(),
            'not_found' => array_values(array_diff([...$ids, ...$orderIds], $found)),
        ]);
    }

    public function cancel(Request $request, string $reference, ProcessPayout $process): JsonResponse
    {
        $payout = $this->query($request)->where('reference', $reference)->first()
            ?? throw new ApiException('not_found', 'No payout with this id for your account.', 404);

        if (! $process->cancel($payout, 'partner_api', $this->key($request)->id)) {
            throw new ApiException('not_cancellable', "A payout in status {$payout->fresh()?->status} can't be cancelled (the branch may already be paying it).", 409);
        }

        return response()->json(['data' => PayoutData::forPartner($payout->refresh())]);
    }

    /**
     * GET /v1/balance: what you can withdraw.
     */
    public function balance(Request $request, PartnerBalance $balances): JsonResponse
    {
        return response()->json(['data' => [...$balances->summary($this->partner($request)), 'currency' => 'INR']]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function query(Request $request): Builder
    {
        return Transaction::query()
            ->with(['customer', 'beneficiary'])
            ->where('partner_id', $this->partner($request)->id)
            ->where('direction', 'payout');
    }

    private function partner(Request $request): Partner
    {
        /** @var Partner */
        return $request->attributes->get('partner');
    }

    private function key(Request $request): PartnerApiKey
    {
        /** @var PartnerApiKey */
        return $request->attributes->get('api_key');
    }
}
