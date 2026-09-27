<?php

namespace App\Providers;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Identity\Policies\UserPolicy;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Core\Rbac\Policies\RolePolicy;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureAuthorization();
    }

    /**
     * Polymorphic columns (audit subject, file owner, notifiable) store these short
     * names instead of PHP class names, so moving a class never breaks stored data.
     * Every model used polymorphically must be listed here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'partner' => Partner::class,
            'branch' => Branch::class,
            'mapping' => PartnerBranchMapping::class,
            'audit_log' => AuditLog::class,
            'security_log' => SecurityLog::class,
        ]);
    }

    /**
     * Every Permission enum value is a Gate ability, resolved from the user's role:
     * `$user->can('partners.update')`, `Gate::authorize(Permission::PartnersUpdate->value)`.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            $permission = Permission::tryFrom($ability);

            return $permission === null ? null : $user->hasPermission($permission);
        });

        // Record-level rules (scope, escalation, locked roles) on top of permissions.
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
