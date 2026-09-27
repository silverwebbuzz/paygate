<?php

namespace App\Http\Middleware;

use App\Domain\Core\Audit\Enums\SecurityEvent;
use App\Domain\Core\Audit\Models\SecurityLog;
use App\Domain\Core\Identity\Enums\UserType;
use App\Domain\Core\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps each portal to its own users: `user.type:admin`, `user.type:partner`, `user.type:branch`.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isType(UserType::from($type))) {
            SecurityLog::record(SecurityEvent::AccessDenied, $user instanceof User ? $user : null, context: [
                'required_type' => $type,
                'path' => $request->path(),
            ]);

            abort(403);
        }

        return $next($request);
    }
}
