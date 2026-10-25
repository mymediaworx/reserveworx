<?php

namespace App\Console\Commands;

use App\Services\Staff\StaffUndo;
use Illuminate\Console\Command;

class CommitStaffUndoActions extends Command
{
    protected $signature = 'undo:commit';

    protected $description = 'Finish staff destructive actions whose undo window has passed.';

    public function handle(StaffUndo $undo): int
    {
        $this->line('undo_committed='.$undo->commitDue());

        return self::SUCCESS;
    }
}
