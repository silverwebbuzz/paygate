<?php

namespace App\Http\Shared\Users\Requests;

use App\Domain\Core\Identity\Concerns\PasswordValidationRules;
use App\Domain\Core\Identity\Concerns\ProfileValidationRules;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new user, with the password the creator sets. The portal and
 * organisation come from the screen (route), never from the form.
 */
class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->actor()->can('create', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => $this->usernameRules(),
            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')],
            'password' => $this->passwordRules(),
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    public function role(): Role
    {
        return Role::query()->findOrFail((string) $this->input('role_id'));
    }
}
