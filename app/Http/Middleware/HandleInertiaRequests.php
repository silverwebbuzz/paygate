<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'role' => $request->user()?->role?->only(['name', 'slug']),
                // UI hints only (show/hide menu items) — every action is re-checked on the server.
                'permissions' => $request->user()?->permissionNames() ?? [],
            ],
            // The bell: unread count and the latest alerts (G-47).
            'alerts' => fn () => $request->user() === null ? null : [
                'unread' => $request->user()->unreadNotifications()->count(),
                'latest' => $request->user()->notifications()->latest()->limit(8)->get()->map(fn ($notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? '',
                    'body' => $notification->data['body'] ?? '',
                    'url' => $notification->data['url'] ?? null,
                    'read' => $notification->read_at !== null,
                    'at' => $notification->created_at?->toIso8601String(),
                ]),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Admin › QA Checklist in the menu (local and staging only).
            'qaChecklist' => $request->user()?->type->value === 'admin' && config('paygate.qa.enabled') && ! app()->isProduction(),
            // Environment label for the top-bar pill ("Admin · Production").
            'environment' => app()->environment(),
        ];
    }
}
