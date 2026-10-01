<?php

namespace App\Http\Admin\Roles;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Actions\DeleteRole;
use App\Domain\Core\Rbac\Actions\SaveRole;
use App\Domain\Core\Rbac\Enums\Menu;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Http\Admin\Roles\Requests\StoreRoleRequest;
use App\Http\Admin\Roles\Requests\UpdateRoleRequest;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles & Permissions (design: role list + menu × View/Insert/Update/Delete grid).
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        /** @var User $actor */
        $actor = $request->user();
        $type = UserType::tryFrom((string) $request->query('type')) ?? UserType::Admin;

        // The super admin role (the developers) is shown to super admins only.
        $visible = fn ($query) => $query->when(! $actor->isSuperAdmin(), fn ($query) => $query
            ->where(fn ($query) => $query->whereNull('slug')->orWhere('slug', '!=', SystemRoles::ADMIN_SUPER)));

        $roles = Role::query()
            ->where('user_type', $type)
            ->tap($visible)
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/roles/index', [
            'type' => $type->value,
            'counts' => Role::query()->tap($visible)->toBase()->selectRaw('user_type, count(*) as total')->groupBy('user_type')->pluck('total', 'user_type'),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'is_locked' => $role->isLocked(),
                'status' => $role->status,
                'users_count' => $role->users_count,
                'permissions' => $role->permissionValues(),
                'can' => [
                    'update' => $actor->can('update', $role),
                    'delete' => $actor->can('delete', $role),
                ],
            ]),
            'selected' => $request->query('role'),
            'menus' => $this->menus($type),
            'grantable' => $actor->permissionNames(),
            'can' => ['create' => $actor->can('create', Role::class)],
        ]);
    }

    public function store(StoreRoleRequest $request, SaveRole $save): RedirectResponse
    {
        $type = UserType::from($request->string('user_type')->value());
        $role = $save->create($request->actor(), $type, $request->string('name')->value(), $request->input('description'), $request->permissions());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role “:name” created.', ['name' => $role->name])]);

        return to_route('admin.roles.index', ['type' => $type->value, 'role' => $role->id]);
    }

    public function update(UpdateRoleRequest $request, Role $role, SaveRole $save): RedirectResponse
    {
        $save->update(
            $request->actor(),
            $role,
            $request->string('name')->value(),
            $request->input('description'),
            $request->string('status')->value(),
            $request->permissions(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions saved for role “:name”.', ['name' => $role->name])]);

        return to_route('admin.roles.index', ['type' => $role->user_type->value, 'role' => $role->id]);
    }

    public function destroy(Request $request, Role $role, DeleteRole $delete): RedirectResponse
    {
        Gate::authorize('delete', $role);

        /** @var User $actor */
        $actor = $request->user();
        $delete->handle($actor, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role “:name” deleted.', ['name' => $role->name])]);

        return to_route('admin.roles.index', ['type' => $role->user_type->value]);
    }

    /**
     * Grid rows for a portal: each menu with the actions it offers.
     *
     * @return list<array{key: string, label: string, group: string, permissions: list<array{value: string, action: string}>}>
     */
    private function menus(UserType $type): array
    {
        $rows = [];

        foreach (Menu::cases() as $menu) {
            $permissions = $menu->permissions($type);

            if ($permissions === []) {
                continue;
            }

            $rows[] = [
                'key' => $menu->value,
                'label' => $menu->label(),
                'group' => $menu->group(),
                'permissions' => array_map(fn (Permission $permission) => [
                    'value' => $permission->value,
                    'action' => $permission->action(),
                ], $permissions),
            ];
        }

        return $rows;
    }
}
