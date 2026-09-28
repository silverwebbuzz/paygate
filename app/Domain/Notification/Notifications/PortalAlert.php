<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Core\Identity\Models\User;
use App\Domain\Notification\Enums\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One alert: stored for the portal's bell, and emailed when the person
 * wants email for this event (their notification preferences).
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
        return $notifiable->wantsEmail($this->alert) && $notifiable->email_verified_at !== null ? ['database', 'mail'] : ['database'];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(User $notifiable): array
    {
        return ['alert' => $this->alert->value, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('PayGate: '.$this->title)
            ->greeting($this->title)
            ->line($this->body);

        if ($this->url !== null) {
            $message->action(__('Open PayGate'), $this->url);
        }

        return $message->line(__('You can turn these emails off under Profile & settings › Notifications.'));
    }
}
