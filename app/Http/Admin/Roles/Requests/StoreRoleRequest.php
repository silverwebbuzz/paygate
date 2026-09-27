<?php

namespace App\Http\Admin\Roles\Requests;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('create', Role::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_type' => ['required', Rule::enum(UserType::class)],
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('user_type', $this->input('user_type'))],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_map(
            fn (string $value) => Permission::from($value),
            array_unique((array) $this->input('permissions', [])),
        ));
    }
}
