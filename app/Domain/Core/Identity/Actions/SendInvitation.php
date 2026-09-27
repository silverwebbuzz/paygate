<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Core\Identity\Notifications\UserInvitation;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Emails a (new) invitation link. Any earlier link for the user stops working.
 */
class SendInvitation
{
    public function handle(User $actor, User $user): void
    {
        if (! $user->isInvited()) {
            throw ValidationException::withMessages(['user' => __('This user has already set a password.')]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['user' => __('Reactivate the user before sending an invitation.')]);
        }

        $token = Password::broker('invites')->createToken($user);

        $user->notify(new UserInvitation($token, $actor->name));

        AuditLog::record('user.invitation_sent', $user, [], ['email' => $user->email], $actor);
    }
}
