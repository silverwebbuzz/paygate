<?php

namespace App\Http\Admin\Roles\Requests;

use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends StoreRoleRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('update', $this->role());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->role();

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('user_type', $role->user_type->value)->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ];
    }

    private function role(): Role
    {
        /** @var Role */
        return $this->route('role');
    }
}
