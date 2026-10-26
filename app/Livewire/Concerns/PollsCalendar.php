<?php

namespace App\Livewire\Concerns;

/**
 * Staff calendar screens re-read rows on a timer. Spec Section 2.3 says
 * calendar refresh uses polling, not a websocket. Livewire already skips
 * the timer while the browser tab is hidden, unless keep-alive is set.
 */
trait PollsCalendar
{
    /**
     * Re-read the current rows. Livewire calls this every 60 seconds. It writes nothing.
     */
    public function refreshCalendar(): void {}
}
