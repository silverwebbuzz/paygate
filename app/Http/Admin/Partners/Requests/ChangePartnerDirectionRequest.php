<?php

namespace App\Http\Admin\Partners\Requests;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePartnerDirectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('partners.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['payin', 'payout'])],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
