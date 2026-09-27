<?php

namespace App\Http\Admin\Partners\Requests;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Organisation\Enums\OrganisationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePartnerStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OrganisationStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
