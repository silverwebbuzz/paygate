<?php

namespace App\Http\Middleware;

use App\Domain\Core\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin and Branch users must set up two-factor authentication before using their portal.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            config('paygate.auth.enforce_two_factor')
            && $user instanceof User
            && $user->type->requiresTwoFactor()
            && ! $user->hasEnabledTwoFactorAuthentication()
        ) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Set up two-factor authentication to continue.'),
            ]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
