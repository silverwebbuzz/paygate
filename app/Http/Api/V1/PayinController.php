<?php

namespace App\Http\Api\V1;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerApiKey;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\Transaction\Actions\ClosePayin;
use App\Domain\Transaction\Actions\CreatePayin;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use App\Http\Api\V1\Requests\CreatePayinRequest;
use App\Http\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner API v1: pay-ins. Every query is scoped to the calling partner.
 */
class PayinController extends Controller
{
    /**
     * POST /v1/payins: 201 with the pay-in; 200 with the original when the
     * same order_id is sent again with the same details; 409 otherwise.
     */
    public function store(CreatePayinRequest $request, CreatePayin $create): JsonResponse
    {
        /** @var array{order_id: string, amount: int, customer: array{id: string, name?: string|null, email?: string|null, mobile?: string|null, username?: string|null}, return_url?: string|null, metadata?: array<string, mixed>|null} $data */
        $data = $request->validated();
        $result = $create->handle($this->partner($request), $data, $this->key($request)->id);

        return response()->json(
            ['data' => PayinResource::make($result['payin']->load('customer'), $result['session'])],
            $result['created'] ? 201 : 200,
        );
    }

    /**
     * GET /v1/payins/{id} (our id) or GET /v1/payins?order_id=… (your id).
     */
    public function show(Request $request, ?string $reference = null): JsonResponse
    {
        $orderId = $request->query('order_id');

        $payin = $this->query($request)
            ->when($reference !== null, fn ($query) => $query->where('reference', $reference))
            ->when($reference === null, fn ($query) => $query->where('partner_transaction_id', is_string($orderId) ? $orderId : ''))
            ->first();

        if ($payin === null) {
            throw new ApiException('not_found', 'No pay-in with this id for your account.', 404);
        }

        return response()->json(['data' => PayinResource::make($payin)]);
    }

    /**
     * POST /v1/payins/status: up to 100 pay-ins at once, by `ids` and/or
     * `order_ids`. Unknown ones are listed in `not_found`.
     */
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

        $payins = $this->query($request)
            ->where(fn ($query) => $query->whereIn('reference', $ids)->orWhereIn('partner_transaction_id', $orderIds))
            ->get();

        $found = $payins->pluck('reference')->merge($payins->pluck('partner_transaction_id'))->all();

        return response()->json([
            'data' => $payins->map(fn (Transaction $payin) => PayinResource::make($payin))->values(),
            'not_found' => array_values(array_diff([...$ids, ...$orderIds], $found)),
        ]);
    }

    /**
     * POST /v1/payins/{id}/cancel: only while the customer hasn't paid yet.
     */
    public function cancel(Request $request, string $reference, ClosePayin $close): JsonResponse
    {
        $payin = $this->query($request)->where('reference', $reference)->first()
            ?? throw new ApiException('not_found', 'No pay-in with this id for your account.', 404);

        if (! $close->handle($payin, PayinStatus::Cancelled, 'partner_api', $this->key($request)->id, 'Cancelled by the partner.')) {
            throw new ApiException('not_cancellable', "A pay-in in status {$payin->fresh()?->status} can't be cancelled.", 409);
        }

        return response()->json(['data' => PayinResource::make($payin->refresh())]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function query(Request $request)
    {
        return Transaction::query()
            ->with(['session', 'customer'])
            ->where('partner_id', $this->partner($request)->id)
            ->where('direction', 'payin');
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
