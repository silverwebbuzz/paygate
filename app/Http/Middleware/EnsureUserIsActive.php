<?php

namespace App\Http\Middleware;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was suspended while logged in.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            SecurityLog::record(SecurityEvent::SessionTerminatedSuspended, $user);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Your account has been suspended. Contact an administrator.'),
            ]);
        }

        return $next($request);
    }
}
