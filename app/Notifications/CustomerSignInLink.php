<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerSignInLink extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public string $url,
        public string $workspaceName,
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
            ->subject('Sign in to '.$this->workspaceName)
            ->line('Use the button below to sign in to '.$this->workspaceName.'. This link expires in 60 minutes.')
            ->action('Sign in', $this->url)
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
