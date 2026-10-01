<?php

namespace App\Http\Shared\Users;

use App\Domain\Branch\Models\Branch;
use App\Domain\Core\Identity\Actions\ChangeUserStatus;
use App\Domain\Core\Identity\Actions\CreateUser;
use App\Domain\Core\Identity\Actions\ResetUserTwoFactor;
use App\Domain\Core\Identity\Actions\SetUserPassword;
use App\Domain\Core\Identity\Actions\UpdateUser;
use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Models\Role;
use App\Domain\Partner\Models\Partner;
use App\Http\Controller;
use App\Http\Shared\Users\Requests\ChangeUserStatusRequest;
use App\Http\Shared\Users\Requests\SetUserPasswordRequest;
use App\Http\Shared\Users\Requests\StoreUserRequest;
use App\Http\Shared\Users\Requests\UpdateUserRequest;
use App\Http\Shared\Users\Requests\UserActionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users screen for all three portals. Admin sees every user but adds only
 * admin users there; each partner's and branch's users are added on that
 * partner's / branch's own Users screen (/admin/partners/{id}/users). Partner
 * and branch owners see and manage their own organisation's users
 * (UserPolicy).
 */
class UserController extends Controller
{
    private const FILTER_STATUSES = ['active', 'inactive'];

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function partnerIndex(Request $request, Partner $partner): Response
    {
        return $this->render($request, $partner);
    }

    public function branchIndex(Request $request, Branch $branch): Response
    {
        return $this->render($request, $branch);
    }

    /**
     * Admin's Users screen adds admin users; partner and branch owners add
     * users to their own organisation.
     */
    public function store(StoreUserRequest $request, CreateUser $create): RedirectResponse
    {
        return $this->createUser($request, $create, $request->actor()->type, null);
    }

    public function partnerStore(StoreUserRequest $request, Partner $partner, CreateUser $create): RedirectResponse
    {
        return $this->createUser($request, $create, UserType::Partner, $partner->id);
    }

    public function branchStore(StoreUserRequest $request, Branch $branch, CreateUser $create): RedirectResponse
    {
        return $this->createUser($request, $create, UserType::Branch, $branch->id);
    }

    /**
     * @param  Partner|Branch|null  $organisation  set on a partner's / branch's own Users screen (Admin)
     */
    private function render(Request $request, Partner|Branch|null $organisation): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = $this->actor($request);
        $type = $actor->isType(UserType::Admin) && $organisation === null ? UserType::tryFrom((string) $request->query('type')) : null;
        $status = in_array($request->query('status'), self::FILTER_STATUSES, true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('search'));
        // The portal of the users this screen adds.
        $addType = match (true) {
            $organisation instanceof Partner => UserType::Partner,
            $organisation instanceof Branch => UserType::Branch,
            default => $actor->type,
        };

        $users = $this->scoped($actor, $organisation)
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
                'organisation' => ($org = $user->partner ?? $user->branch) ? ['name' => $org->name, 'code' => $org->code] : null,
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
            'organisation' => $organisation === null ? null : [
                'type' => $addType->value,
                'id' => $organisation->id,
                'name' => $organisation->name,
                'code' => $organisation->code,
                'users_url' => $organisation instanceof Partner
                    ? route('admin.partners.users.index', $organisation)
                    : route('admin.branches.users.index', $organisation),
                'store_url' => $organisation instanceof Partner
                    ? route('admin.partners.users.store', $organisation)
                    : route('admin.branches.users.store', $organisation),
                'back_url' => $organisation instanceof Partner
                    ? route('admin.partners.index', ['partner' => $organisation->id])
                    : route('admin.branches.index', ['branch' => $organisation->id]),
            ],
            'users' => $users,
            'filters' => ['type' => $type?->value, 'status' => $status, 'search' => $search],
            'counts' => $this->counts($actor, $organisation),
            'roles' => $this->assignableRoles($actor),
            'can' => ['create' => $actor->can('create', User::class)],
        ]);
    }

    private function createUser(StoreUserRequest $request, CreateUser $create, UserType $type, ?string $organisationId): RedirectResponse
    {
        $user = $create->handle(
            $request->actor(),
            $type,
            $organisationId,
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->role(),
            $request->string('password')->value(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can now log in as :email.', ['name' => $user->name, 'email' => $user->email])]);

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
        $status = $request->status();
        $change->handle($request->actor(), $user, $status, $request->string('reason')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => $status === UserStatus::Suspended
            ? __(':name deactivated.', ['name' => $user->name])
            : __(':name activated.', ['name' => $user->name])]);

        return back();
    }

    public function setPassword(SetUserPasswordRequest $request, User $user, SetUserPassword $set): RedirectResponse
    {
        $set->handle($request->actor(), $user, $request->string('password')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New password set for :name.', ['name' => $user->name])]);

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
    private function scoped(User $actor, Partner|Branch|null $organisation = null): Builder
    {
        return match ($actor->type) {
            UserType::Admin => match (true) {
                $organisation instanceof Partner => User::query()->where('partner_id', $organisation->id),
                $organisation instanceof Branch => User::query()->where('branch_id', $organisation->id),
                default => User::query(),
            },
            UserType::Partner => User::query()->where('partner_id', $actor->partner_id),
            UserType::Branch => User::query()->where('branch_id', $actor->branch_id),
        };
    }

    /**
     * @param  Builder<User>  $query
     */
    private function whereDisplayStatus(Builder $query, string $status): void
    {
        $query->where('status', $status === 'inactive' ? UserStatus::Suspended : UserStatus::Active);
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $actor, Partner|Branch|null $organisation): array
    {
        $counts = ['all' => $this->scoped($actor, $organisation)->count()];

        if ($actor->isType(UserType::Admin) && $organisation === null) {
            foreach (UserType::cases() as $type) {
                $counts[$type->value] = User::query()->where('type', $type)->count();
            }
        }

        foreach (self::FILTER_STATUSES as $status) {
            $query = $this->scoped($actor, $organisation);
            $this->whereDisplayStatus($query, $status);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    /**
     * Roles the actor may hand out: active, of a portal they manage, and no
     * more powerful than their own. The page offers the add-portal's roles
     * when adding, and the user's portal's roles when editing.
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
