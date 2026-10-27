<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer reminder. The visit line is already formatted in the location zone.
 * This notification does not read or change a price, a payment, a tax, a fee, or a refund.
 */
class BookingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $serviceName,
        public string $when,
        public string $reference,
        public int $offsetMinutes,
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
            'when' => $this->when,
            'reference' => $this->reference,
            'ahead' => $this->offsetMinutes === 1
                ? '1 minute'
                : $this->offsetMinutes.' minutes',
        ];

        return (new MailMessage)
            ->subject('Booking reminder')
            ->view('mail.booking-reminder', $data)
            ->text('mail.booking-reminder-text', $data);
    }
}
