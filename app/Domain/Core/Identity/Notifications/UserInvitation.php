<?php

namespace App\Domain\Core\Identity\Notifications;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation email with a link to set a password (valid 72 hours, see the
 * "invites" password broker in config/auth.php).
 */
class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly string $invitedBy,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('invitation.show', ['token' => $this->token, 'email' => $notifiable->email]);
        $portal = $notifiable->type->label();
        $organisation = $notifiable->partner->name ?? $notifiable->branch->name ?? config('app.name');

        return (new MailMessage)
            ->subject(__('You have been invited to :app', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':inviter invited you to the :portal portal for :organisation.', [
                'inviter' => $this->invitedBy,
                'portal' => $portal,
                'organisation' => $organisation,
            ]))
            ->action(__('Set your password'), $url)
            ->line(__('This link expires in 72 hours. If you weren\'t expecting this email, you can ignore it.'));
    }
}
