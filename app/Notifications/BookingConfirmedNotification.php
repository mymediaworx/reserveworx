<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $serviceName,
        public string $checkInUrl,
        public string $checkInSvg,
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
        $data = [
            'serviceName' => $this->serviceName,
            'checkInUrl' => $this->checkInUrl,
            'checkInSvg' => $this->checkInSvg,
        ];
        $message = (new MailMessage)
            ->subject('Your booking is confirmed')
            ->view('mail.booking-confirmed', $data)
            ->text('mail.booking-confirmed-text', $data);

        if (is_string($this->replyTo) && $this->replyTo !== '') {
            $message->replyTo($this->replyTo);
        }

        return $message;
    }
}
