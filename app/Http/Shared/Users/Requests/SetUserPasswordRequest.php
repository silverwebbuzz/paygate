<?php

namespace App\Http\Shared\Users\Requests;

use App\Domain\Core\Identity\Concerns\PasswordValidationRules;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetUserPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->actor()->can('update', $this->target());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['password' => $this->passwordRules()];
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
