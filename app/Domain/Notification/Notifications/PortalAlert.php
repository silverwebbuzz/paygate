<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\Enums\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One alert, stored for the portal's bell, unless the person turned this
 * alert off (their notification preferences). No email: the system doesn't
 * send any (client request 2026-11-25).
 */
class PortalAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Alert $alert,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->wantsAlert($this->alert) ? ['database'] : [];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(User $notifiable): array
    {
        return ['alert' => $this->alert->value, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
