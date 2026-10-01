<?php

namespace App\Http\Shared\Users\Requests;

use App\Domain\Core\Identity\Enums\UserStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class ChangeUserStatusRequest extends UserActionRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** Inactive users are stored as `suspended`. */
    public function status(): UserStatus
    {
        return $this->input('status') === 'active' ? UserStatus::Active : UserStatus::Suspended;
    }
}
