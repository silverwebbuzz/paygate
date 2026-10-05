<?php

namespace Database\Factories;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\SystemRoles;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Least-privileged role by default; use admin()/partner()/branch() for others.
            'type' => UserType::Partner,
            'role_id' => fn () => Role::bySlug(SystemRoles::PARTNER_VIEWER)->id,
            'partner_id' => Partner::factory(),
            'branch_id' => null,
            'status' => UserStatus::Active,
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    /**
     * Give the user a built-in role by slug (see App\Domain\Core\Rbac\SystemRoles). Partner and
     * branch users get a new partner / branch unless one is passed.
     */
    public function role(string $slug, Partner|Branch|null $organisation = null): static
    {
        return $this->state(function () use ($slug, $organisation) {
            $role = Role::bySlug($slug);

            return [
                'type' => $role->user_type,
                'role_id' => $role->id,
                'partner_id' => $role->user_type === UserType::Partner ? ($organisation?->getKey() ?? Partner::factory()) : null,
                'branch_id' => $role->user_type === UserType::Branch ? ($organisation?->getKey() ?? Branch::factory()) : null,
            ];
        });
    }

    public function admin(string $slug = SystemRoles::ADMIN_SUPER): static
    {
        return $this->role($slug);
    }

    public function partner(string $slug = SystemRoles::PARTNER_OWNER, ?Partner $partner = null): static
    {
        return $this->role($slug, $partner);
    }

    public function branch(string $slug = SystemRoles::BRANCH_OWNER, ?Branch $branch = null): static
    {
        return $this->role($slug, $branch);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Suspended,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
