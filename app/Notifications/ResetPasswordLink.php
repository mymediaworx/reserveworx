<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLink extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $url) {}

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
            ->subject('Reset your ReserveWorx password')
            ->line('Use the button below to choose a new password. This link expires in 60 minutes.')
            ->action('Reset password', $this->url)
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
