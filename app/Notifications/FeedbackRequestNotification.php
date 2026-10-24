<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FeedbackRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $businessName,
        public string $serviceName,
        public string $rateUrl,
        public string $optOutUrl,
        public ?string $replyTo,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = $this->serviceName !== '' ? $this->serviceName : 'your booking';
        $message = (new MailMessage)
            ->subject('How was your time with '.$this->businessName.'?')
            ->line('Thanks for choosing '.$service.' with '.$this->businessName.'.')
            ->line('You can rate the visit from 1 to 5 and leave a comment.')
            ->action('Rate your visit', $this->rateUrl)
            ->line('If you would rather not get these notes, you can opt out: '.$this->optOutUrl);

        if (is_string($this->replyTo) && $this->replyTo !== '') {
            $message->replyTo($this->replyTo);
        }

        return $message;
    }
}
