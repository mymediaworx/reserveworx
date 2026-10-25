<?php

namespace App\Livewire\Concerns;

use App\Models\StaffUndoAction;
use App\Services\Staff\StaffUndo;

/**
 * Toast state for a staff action that can be undone for ten seconds.
 */
trait PresentsUndoToast
{
    public ?string $undoActionId = null;

    public ?string $undoMessage = null;

    public ?string $undoExpiresAt = null;

    protected function rememberUndo(StaffUndoAction $action): void
    {
        $this->undoActionId = $action->id;
        $this->undoMessage = $action->message;
        $this->undoExpiresAt = $action->expires_at->toIso8601String();
    }

    public function undoAction(string $id, StaffUndo $undo): void
    {
        $undo->restore($this->currentUser(), $id);

        if ($this->undoActionId === $id) {
            $this->clearUndoToast();
        }

        $this->flash = 'Undone.';

        if (property_exists($this, 'problem')) {
            $this->problem = null;
        }
    }

    public function dismissUndo(?string $id = null): void
    {
        if (is_string($id) && $id !== '') {
            app(StaffUndo::class)->commitIfDue($id);
        }

        $this->clearUndoToast();
    }

    protected function clearUndoToast(): void
    {
        $this->undoActionId = null;
        $this->undoMessage = null;
        $this->undoExpiresAt = null;
    }
}
