<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistJoinedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $serviceName,
        public string $when,
        public int $participants,
        public string $leaveUrl,
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
        $party = $this->participants === 1 ? '1 person' : $this->participants.' people';
        $data = [
            'serviceName' => $this->serviceName,
            'when' => $this->when,
            'party' => $party,
            'leaveUrl' => $this->leaveUrl,
        ];
        $message = (new MailMessage)
            ->subject('You are on the waitlist')
            ->view('mail.waitlist-joined', $data)
            ->text('mail.waitlist-joined-text', $data);

        if (is_string($this->replyTo) && $this->replyTo !== '') {
            $message->replyTo($this->replyTo);
        }

        return $message;
    }
}
