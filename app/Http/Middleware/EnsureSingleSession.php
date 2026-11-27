<?php

namespace App\Http\Middleware;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Models\User;
use App\Support\SingleSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * One login per user at a time: when the same username signs in on another
 * browser or computer, the older session is ended here on its next request.
 * The token is set at sign-in by RecordSecurityEvents::handleLogin.
 */
class EnsureSingleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->session_token !== null && $request->hasSession()) {
            $token = $request->session()->get(SingleSession::SESSION_KEY);

            if (! is_string($token) || ! hash_equals($user->session_token, $token)) {
                SecurityLog::record(SecurityEvent::SessionReplaced, $user);

                // Only this browser: the newer session stays signed in.
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'login' => __('You were signed out because your account signed in on another browser or computer.'),
                ]);
            }
        }

        return $next($request);
    }
}
