<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvite extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public string $url,
        public string $businessName,
        public string $roleName,
        public int $expiresInDays,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Join '.$this->businessName.' on ReserveWorx')
            ->line('You are invited to '.$this->businessName.' as '.$this->roleName.'. This link expires in '.$this->expiresInDays.' days.')
            ->action('Accept invitation', $this->url)
            ->line('If you did not expect this, you can ignore this email.');
    }
}
