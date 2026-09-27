<?php

namespace App\Domain\Core\Rbac\Models;

use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\SystemRoles;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * An editable role. Permissions come from the code catalogue (App\Domain\Core\Rbac\Enums\Permission);
 * the database guarantees users only hold roles of their own portal type.
 *
 * @property string $id
 * @property UserType $user_type
 * @property string|null $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = ['user_type', 'slug', 'name', 'description', 'is_system', 'status'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'is_system' => 'boolean',
        ];
    }

    public static function bySlug(string $slug): self
    {
        return self::where('slug', $slug)->firstOrFail();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The super admin role can't be edited, suspended or deleted, and always
     * holds every admin permission (see SystemRoles).
     */
    public function isLocked(): bool
    {
        return $this->slug === SystemRoles::ADMIN_SUPER;
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        if ($this->isLocked()) {
            return array_map(fn (Permission $permission) => $permission->value, Permission::forType(UserType::Admin));
        }

        return $this->permissionCache ??= array_values(array_map(
            'strval',
            DB::table('role_permissions')->where('role_id', $this->id)->orderBy('permission')->pluck('permission')->all(),
        ));
    }

    /**
     * Whether `$user` may hand this role out (or edit it): a user can never
     * grant more than they hold themselves.
     */
    public function isWithinPermissionsOf(User $user): bool
    {
        $granted = array_filter($this->permissionValues(), fn (string $value) => Permission::tryFrom($value) !== null);

        return array_diff($granted, $user->permissionNames()) === [];
    }

    public function grants(Permission $permission): bool
    {
        return $this->isActive()
            && $permission->allowedFor($this->user_type)
            && in_array($permission->value, $this->permissionValues(), true);
    }

    /**
     * Stops `$actor` giving this role to a user of `$type`: it must be an
     * active role of that portal, and no more powerful than the actor's own.
     *
     * @throws ValidationException
     */
    public function ensureAssignableBy(User $actor, UserType $type, string $field = 'role_id'): void
    {
        $error = match (true) {
            $this->user_type !== $type => __('Choose a :portal role.', ['portal' => $type->label()]),
            ! $this->isActive() => __('This role is inactive.'),
            ! $this->isWithinPermissionsOf($actor) => __('You can only assign roles with permissions you hold yourself.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$field => $error]);
        }
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        if ($this->isLocked()) {
            throw new InvalidArgumentException('The super admin role always holds every permission and cannot be changed.');
        }

        foreach ($permissions as $permission) {
            if (! $permission->allowedFor($this->user_type)) {
                throw new InvalidArgumentException("Permission {$permission->value} is not available to {$this->user_type->value} roles.");
            }
        }

        DB::transaction(function () use ($permissions) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(array_map(
                fn (Permission $permission) => ['role_id' => $this->id, 'permission' => $permission->value],
                array_values(array_unique($permissions, SORT_REGULAR)),
            ));
        });

        $this->permissionCache = null;
    }
}
