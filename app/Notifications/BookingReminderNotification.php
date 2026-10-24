<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $bookingId,
        public int $offsetMinutes,
        public string $localStartsAt,
        public string $timezone,
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
        return (new MailMessage)
            ->subject('Booking reminder')
            ->line('A booking starts at '.$this->localStartsAt.' ('.$this->timezone.').')
            ->line('This reminder is '.$this->offsetMinutes.' minutes before the start.')
            ->line('Booking '.$this->bookingId.'.');
    }
}
