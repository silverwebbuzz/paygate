<?php

namespace App\Http\Middleware;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Platform\SectionRollout;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section rollout: while it is on, users other than super admins can't open
 * a section that isn't open yet. Opening one sends them back to their
 * dashboard with a message; any other request is refused.
 */
class EnforceSectionRollout
{
    public function __construct(private SectionRollout $rollout) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($this->rollout->allows($user, $request->path())) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET') && $user !== null, 403, __('This section isn’t open yet.'));

        Inertia::flash('toast', ['type' => 'info', 'message' => __('This section isn’t open yet.')]);

        return redirect()->route($user->type->homeRoute());
    }
}
