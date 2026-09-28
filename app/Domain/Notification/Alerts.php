<?php

namespace App\Domain\Notification;

use App\Domain\Core\Identity\Enums\UserStatus;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Rbac\Enums\Permission;
use App\Domain\Notification\Enums\Alert;
use App\Domain\Notification\Notifications\PortalAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends an alert to the people who can act on it: active users of a portal
 * (and organisation) holding a permission. Queued and sent after the
 * database transaction commits (G-47).
 */
class Alerts
{
    /**
     * @param  Collection<int, User>  $recipients
     */
    public function send(Alert $alert, Collection $recipients, string $title, string $body, ?string $url = null): void
    {
        $recipients = $recipients->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new PortalAlert($alert, $title, $body, $url));
        }
    }

    /**
     * Active users of a portal (and organisation) who hold a permission.
     *
     * @return Collection<int, User>
     */
    public function recipients(Permission $permission, UserType $type, ?string $organisationId = null, ?User $except = null): Collection
    {
        return User::query()
            ->with('role')
            ->where('type', $type->value)
            ->where('status', UserStatus::Active->value)
            ->when($type === UserType::Partner && $organisationId !== null, fn ($query) => $query->where('partner_id', $organisationId))
            ->when($type === UserType::Branch && $organisationId !== null, fn ($query) => $query->where('branch_id', $organisationId))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->id))
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($permission))
            ->values();
    }

    /**
     * A portal link for the alert (absolute, for emails).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function link(string $routeName, array $parameters = []): string
    {
        return route($routeName, $parameters);
    }
}
