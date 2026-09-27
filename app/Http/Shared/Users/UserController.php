<?php

namespace App\Http\Shared\Users;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Actions\ChangeUserStatus;
use App\Domain\Core\Identity\Actions\InviteUser;
use App\Domain\Core\Identity\Actions\ResetUserTwoFactor;
use App\Domain\Core\Identity\Actions\SendInvitation;
use App\Domain\Core\Identity\Actions\UpdateUser;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Partner\Models\Partner;
use App\Http\Controller;
use App\Http\Shared\Users\Requests\ChangeUserStatusRequest;
use App\Http\Shared\Users\Requests\InviteUserRequest;
use App\Http\Shared\Users\Requests\UpdateUserRequest;
use App\Http\Shared\Users\Requests\UserActionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users screen for all three portals. Admin sees every user; partner and
 * branch owners see and manage their own organisation's users (UserPolicy).
 */
class UserController extends Controller
{
    private const FILTER_STATUSES = ['active', 'invited', 'suspended'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $this->actor($request);
        $isAdmin = $actor->isType(UserType::Admin);
        $type = $isAdmin ? UserType::tryFrom((string) $request->query('type')) : null;
        $status = in_array($request->query('status'), self::FILTER_STATUSES, true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));

        $users = $this->scoped($actor)
            ->with(['role', 'partner', 'branch'])
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->when($status, fn (Builder $query) => $this->whereDisplayStatus($query, (string) $status))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'type' => $user->type->value,
                'organisation' => ($organisation = $user->partner ?? $user->branch) ? ['name' => $organisation->name, 'code' => $organisation->code] : null,
                'role' => ['id' => $user->role->id, 'name' => $user->role->name],
                'status' => $user->displayStatus(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'created_at' => $user->created_at?->toIso8601String(),
                'can' => ['update' => $actor->can('update', $user)],
            ]);

        return Inertia::render('users/index', [
            'portal' => $actor->type->value,
            'users' => $users,
            'filters' => ['type' => $type?->value, 'status' => $status, 'search' => $search],
            'counts' => $this->counts($actor),
            'roles' => $this->assignableRoles($actor),
            'organisations' => $isAdmin ? [
                'partner' => Partner::query()->orderBy('name')->get(['id', 'name', 'code']),
                'branch' => Branch::query()->orderBy('name')->get(['id', 'name', 'code']),
            ] : null,
            'can' => ['create' => $actor->can('create', User::class)],
        ]);
    }

    public function store(InviteUserRequest $request, InviteUser $invite): RedirectResponse
    {
        $user = $invite->handle(
            $request->actor(),
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->role(),
            $request->input('organisation_id'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $user->email])]);

        return back();
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $update): RedirectResponse
    {
        $update->handle($request->actor(), $user, $request->string('name')->value(), $request->string('email')->value(), $request->role());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name updated.', ['name' => $user->name])]);

        return back();
    }

    public function status(ChangeUserStatusRequest $request, User $user, ChangeUserStatus $change): RedirectResponse
    {
        $status = UserStatus::from($request->string('status')->value());
        $change->handle($request->actor(), $user, $status, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => $status === UserStatus::Suspended
            ? __(':name suspended.', ['name' => $user->name])
            : __(':name reactivated.', ['name' => $user->name])]);

        return back();
    }

    public function resendInvitation(Request $request, User $user, SendInvitation $send): RedirectResponse
    {
        Gate::authorize('update', $user);

        $send->handle($this->actor($request), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New invitation sent to :email.', ['email' => $user->email])]);

        return back();
    }

    public function resetTwoFactor(UserActionRequest $request, User $user, ResetUserTwoFactor $reset): RedirectResponse
    {
        $reset->handle($request->actor(), $user, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication removed for :name.', ['name' => $user->name])]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * @return Builder<User>
     */
    private function scoped(User $actor): Builder
    {
        return match ($actor->type) {
            UserType::Admin => User::query(),
            UserType::Partner => User::query()->where('partner_id', $actor->partner_id),
            UserType::Branch => User::query()->where('branch_id', $actor->branch_id),
        };
    }

    /**
     * @param  Builder<User>  $query
     */
    private function whereDisplayStatus(Builder $query, string $status): void
    {
        match ($status) {
            'suspended' => $query->where('status', UserStatus::Suspended),
            'invited' => $query->where('status', UserStatus::Active)->whereNull('email_verified_at')->whereNull('last_login_at'),
            default => $query->where('status', UserStatus::Active)->where(fn (Builder $query) => $query->whereNotNull('email_verified_at')->orWhereNotNull('last_login_at')),
        };
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $actor): array
    {
        $counts = ['all' => $this->scoped($actor)->count()];

        if ($actor->isType(UserType::Admin)) {
            foreach (UserType::cases() as $type) {
                $counts[$type->value] = User::query()->where('type', $type)->count();
            }
        }

        foreach (self::FILTER_STATUSES as $status) {
            $query = $this->scoped($actor);
            $this->whereDisplayStatus($query, $status);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    /**
     * Roles the actor may hand out: active, of a portal they manage, and no
     * more powerful than their own.
     *
     * @return array<int, array{id: string, name: string, user_type: string}>
     */
    private function assignableRoles(User $actor): array
    {
        return Role::query()
            ->where('status', 'active')
            ->when(! $actor->isType(UserType::Admin), fn (Builder $query) => $query->where('user_type', $actor->type))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role) => $role->isWithinPermissionsOf($actor))
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name, 'user_type' => $role->user_type->value])
            ->values()
            ->all();
    }
}
