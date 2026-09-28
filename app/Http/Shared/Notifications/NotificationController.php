<?php

namespace App\Http\Shared\Notifications;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\Enums\Alert;
use App\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell (read / read all) and Profile & settings › Notifications, where
 * a person chooses which alerts also come by email (G-47).
 */
class NotificationController extends Controller
{
    public function read(Request $request, string $notification): RedirectResponse
    {
        $this->actor($request)->notifications()->whereKey($notification)->first()?->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->actor($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function edit(Request $request): Response
    {
        $actor = $this->actor($request);

        // Not "alerts": that name is the shared bell prop.
        return Inertia::render('settings/notifications', [
            'events' => array_map(fn (Alert $alert) => [
                'key' => $alert->value,
                'label' => $alert->label(),
                'email' => $actor->wantsEmail($alert),
            ], Alert::for($actor->type)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $keys = array_map(fn (Alert $alert) => $alert->value, Alert::for($actor->type));
        $data = $request->validate(['email' => ['array'], 'email.*' => ['boolean']]);

        $preferences = [];

        foreach ($keys as $key) {
            $preferences[$key] = (bool) ($data['email'][$key] ?? false);
        }

        $actor->forceFill(['notification_preferences' => $preferences])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return back();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
