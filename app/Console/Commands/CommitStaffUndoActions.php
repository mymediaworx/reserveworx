<?php

namespace App\Console\Commands;

use App\Services\Staff\StaffUndo;
use App\Support\SharedHostBudget;
use Illuminate\Console\Command;

class CommitStaffUndoActions extends Command
{
    protected $signature = 'undo:commit';

    protected $description = 'Finish staff destructive actions whose undo window has passed.';

    public function handle(StaffUndo $undo, SharedHostBudget $budget): int
    {
        $this->line('undo_committed='.$undo->commitDue(fn (): bool => $budget->expired()));

        return self::SUCCESS;
    }
}
