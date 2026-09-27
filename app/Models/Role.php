<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An editable role. Permissions come from the code catalogue (App\Enums\Permission);
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
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return $this->permissionCache ??= array_values(array_map(
            'strval',
            DB::table('role_permissions')->where('role_id', $this->id)->orderBy('permission')->pluck('permission')->all(),
        ));
    }

    public function grants(Permission $permission): bool
    {
        return $this->isActive()
            && $permission->allowedFor($this->user_type)
            && in_array($permission->value, $this->permissionValues(), true);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
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
