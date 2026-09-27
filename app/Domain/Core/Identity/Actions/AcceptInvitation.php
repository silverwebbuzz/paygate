<?php

namespace App\Domain\Core\Identity\Actions;

use App\Domain\Core\Audit\Models\AuditLog;
use App\Domain\Core\Identity\Concerns\PasswordValidationRules;
use App\Domain\Core\Identity\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An invited user sets their password through the emailed link. The link
 * proves they own the email address, so it is marked verified.
 */
class AcceptInvitation
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input  email, token, password, password_confirmation
     */
    public function handle(array $input): User
    {
        Validator::make($input, [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => $this->passwordRules(),
        ])->validate();

        $accepted = null;

        $status = Password::broker('invites')->reset(
            ['email' => $input['email'], 'token' => $input['token'], 'password' => $input['password']],
            function (User $user, string $password) use (&$accepted) {
                $user->forceFill([
                    'password' => $password,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'remember_token' => Str::random(60),
                ])->save();

                AuditLog::record('user.invitation_accepted', $user, [], [], $user);

                $accepted = $user;
            },
        );

        if (! $accepted instanceof User) {
            throw ValidationException::withMessages([
                'email' => $status === Password::INVALID_TOKEN
                    ? __('This invitation link is invalid or has expired. Ask for a new one.')
                    : __($status),
            ]);
        }

        return $accepted;
    }
}
