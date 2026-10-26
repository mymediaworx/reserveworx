<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackRequests;
use Illuminate\Console\Command;

class DispatchFeedbackRequests extends Command
{
    protected $signature = 'feedback:dispatch';

    protected $description = 'Queue one feedback email for each returned or completed booking whose delay has passed.';

    public function handle(FeedbackRequests $feedback): int
    {
        $result = $feedback->dispatchDue();
        $this->line('sweep_visited='.$result->visited);
        $this->line('sweep_deferred='.($result->deferred ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
