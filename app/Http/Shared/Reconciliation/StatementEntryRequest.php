<?php

namespace App\Http\Shared\Reconciliation;

use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Http\Shared\Concerns\ReadsMoney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One manual statement line (design "New statement line"): credit or debit,
 * one value per line.
 */
class StatementEntryRequest extends FormRequest
{
    use ReadsMoney;

    public function authorize(): bool
    {
        /** @var User $actor */
        $actor = $this->user();

        return $actor->can('statements.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_account_id' => ['required', 'uuid'],
            'value_date' => ['required', 'date_format:Y-m-d'],
            'utr' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'credit' => $this->amountRules(),
            'debit' => $this->amountRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $credit = $this->paise('credit');
            $debit = $this->paise('debit');

            if (($credit === null || $credit === 0) === ($debit === null || $debit === 0)) {
                $validator->errors()->add('credit', __('Enter either a credit or a debit amount (one value per line).'));
            }

            if ($this->account() === null) {
                $validator->errors()->add('payment_account_id', __('Choose one of your accounts.'));
            }
        }];
    }

    public function account(): ?PaymentAccount
    {
        /** @var User $actor */
        $actor = $this->user();

        return ReconciliationScope::accounts($actor)->find((string) $this->input('payment_account_id'));
    }

    public function direction(): string
    {
        return ($this->paise('credit') ?? 0) > 0 ? 'credit' : 'debit';
    }

    public function amount(): int
    {
        return (int) ($this->direction() === 'credit' ? $this->paise('credit') : $this->paise('debit'));
    }
}
