<?php

namespace App\Http\Admin\Branches\Requests;

use App\Domain\Core\Identity\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Top-up (or correction) of a branch's deposit allowance, in rupees.
 */
class TopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('branches.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['topup', 'correction'])],
            'amount' => ['required', 'string', Money::RUPEES_RULE],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    /**
     * Positive for a top-up, negative for a correction (reduction).
     */
    public function amountInPaise(): int
    {
        $paise = Money::toPaise($this->string('amount')->value());

        return $this->input('kind') === 'correction' ? -$paise : $paise;
    }
}
