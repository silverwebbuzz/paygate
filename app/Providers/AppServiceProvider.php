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
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Platform\Models\Page;
use App\Domain\Platform\Models\StoredFile;
use App\Domain\Reconciliation\Models\ReconciliationCase;
use App\Domain\Reconciliation\Models\StatementEntry;
use App\Domain\Reconciliation\Models\StatementImport;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Settlement\Models\Adjustment;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Transaction\Models\Transaction;
use App\Domain\Transaction\Policies\TransactionPolicy;
use App\Domain\Webhook\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimits();
    }

    /**
     * Payer page limits, counted separately per page and per action (a
     * plain throttle:N,1 shares one counter per IP across all routes).
     */
    protected function configureRateLimits(): void
    {
        $key = fn (Request $request, string $action) => $action.'|'.$request->ip().'|'.$request->route('token');

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(60)->by($key($request, 'view')));
        RateLimiter::for('checkout-method', fn (Request $request) => Limit::perMinute(20)->by($key($request, 'method')));
        RateLimiter::for('checkout-proof', fn (Request $request) => Limit::perMinute(10)->by($key($request, 'proof')));
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
            'payment_account' => PaymentAccount::class,
            'transaction' => Transaction::class,
            'file' => StoredFile::class,
            'webhook_event' => WebhookEvent::class,
            'statement_entry' => StatementEntry::class,
            'statement_import' => StatementImport::class,
            'reconciliation_case' => ReconciliationCase::class,
            'settlement' => Settlement::class,
            'adjustment' => Adjustment::class,
            'report_export' => ReportExport::class,
            'page' => Page::class,
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
        Gate::policy(Transaction::class, TransactionPolicy::class);

        // Super-admin tools (Section rollout, QA Checklist, UI kit).
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());
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
