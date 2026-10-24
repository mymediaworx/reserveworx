<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifySignupEmail extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public string $url,
        public string $businessName,
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
            ->subject('Verify your email for '.$this->businessName)
            ->line('Confirm this email to open '.$this->businessName.' on ReserveWorx. The link expires in 60 minutes.')
            ->action('Verify email', $this->url)
            ->line('The workspace stays in onboarding until this link is used and the setup steps are finished. If you did not sign up, you can ignore this email.');
    }
}
