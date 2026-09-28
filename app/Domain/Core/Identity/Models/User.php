<?php

namespace App\Domain\Core\Identity\Models;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Partner\Models\Partner;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property UserType $type
 * @property string $role_id
 * @property string|null $partner_id
 * @property string|null $branch_id
 * @property-read Role $role
 * @property-read Partner|null $partner
 * @property-read Branch|null $branch
 * @property UserStatus $status
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property array<string, bool>|null $notification_preferences alert => email on/off
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'email', 'password', 'type', 'role_id', 'partner_id', 'branch_id', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'type' => UserType::class,
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isType(UserType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Invited but hasn't set a password yet (setting it verifies the email).
     */
    public function isInvited(): bool
    {
        return $this->email_verified_at === null && $this->last_login_at === null;
    }

    /**
     * Status for display: active, invited or suspended.
     */
    public function displayStatus(): string
    {
        return match (true) {
            ! $this->isActive() => $this->status->value,
            $this->isInvited() => 'invited',
            default => 'active',
        };
    }

    /**
     * The partner or branch the user works for (null for admins).
     */
    public function organisationId(): ?string
    {
        return $this->partner_id ?? $this->branch_id;
    }

    /**
     * True when this user is the only active super admin left, so they must
     * not be suspended or given another role.
     */
    public function isLastActiveSuperAdmin(): bool
    {
        if (! $this->isActive() || $this->role->slug !== SystemRoles::ADMIN_SUPER) {
            return false;
        }

        return ! self::query()
            ->whereKeyNot($this->getKey())
            ->where('status', UserStatus::Active)
            ->whereRelation('role', 'slug', SystemRoles::ADMIN_SUPER)
            ->exists();
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether this person also wants an alert by email (on unless turned off).
     */
    public function wantsEmail(Alert $alert): bool
    {
        return ($this->notification_preferences[$alert->value] ?? true) !== false;
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->isActive()
            && $permission->allowedFor($this->type)
            && $this->role->grants($permission);
    }

    /**
     * Permission names for the frontend (UI hints only — the server always re-checks).
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if (! $this->isActive() || ! $this->role->isActive()) {
            return [];
        }

        return array_values(array_filter(
            $this->role->permissionValues(),
            fn (string $value) => Permission::tryFrom($value)?->allowedFor($this->type) ?? false,
        ));
    }
}
