<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A plain customer email about a change to a booking: a cancellation, a
 * refund, a new time, an overdue rental, or a failed payment.
 */
class BookingUpdateNotification extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public string $businessName,
        public array $lines,
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
            'subject' => $this->subject,
            'businessName' => $this->businessName,
            'lines' => $this->lines,
        ];
        $message = (new MailMessage)
            ->subject($this->subject)
            ->view('mail.booking-update', $data)
            ->text('mail.booking-update-text', $data);

        if (is_string($this->replyTo) && $this->replyTo !== '') {
            $message->replyTo($this->replyTo);
        }

        return $message;
    }
}
