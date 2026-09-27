<?php

namespace App\Http\Shared\Auth;

use App\Domain\Core\Identity\Actions\AcceptInvitation;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link in the invitation email: the new user sets a password, then logs in.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => (string) $request->query('email'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, AcceptInvitation $accept): RedirectResponse
    {
        $accept->handle($request->only('token', 'email', 'password', 'password_confirmation'));

        return to_route('login')->with('status', __('Your password is set. You can now log in.'));
    }
}
