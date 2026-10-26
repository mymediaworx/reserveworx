<?php

namespace App\Console\Commands;

use App\Services\Notifications\BookingReminders;
use Illuminate\Console\Command;

class DispatchBookingReminders extends Command
{
    protected $signature = 'bookings:dispatch-reminders';

    protected $description = 'Queue one reminder for each confirmed booking that has reached a workspace offset. A log mailer does not send email.';

    public function handle(BookingReminders $reminders): int
    {
        $result = $reminders->dispatchDue();
        $this->line('sweep_visited='.$result->visited);
        $this->line('sweep_deferred='.($result->deferred ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
