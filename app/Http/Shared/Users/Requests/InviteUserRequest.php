<?php

namespace App\Http\Shared\Users\Requests;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('create', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $roleType = Role::query()->find((string) $this->input('role_id'))?->user_type->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')],
            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')],
            // Only Admin chooses the organisation; others invite into their own.
            'organisation_id' => $this->actor()->isType(UserType::Admin) ? match ($roleType) {
                'partner' => ['required', 'uuid', Rule::exists('partners', 'id')],
                'branch' => ['required', 'uuid', Rule::exists('branches', 'id')],
                default => ['prohibited'],
            } : ['prohibited'],
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
