<?php

namespace App\Http\Shared\Users\Requests;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A sensitive action on another user; a reason is required for the audit log.
 */
class UserActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    public function target(): User
    {
        /** @var User */
        return $this->route('user');
    }
}
