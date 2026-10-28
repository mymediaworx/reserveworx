<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\SharedHostLimitReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.platform')]
#[Title('Limits')]
class Limits extends Component
{
    public function render(SharedHostLimitReport $report, Schedule $schedule): View
    {
        $this->guard();

        return view('livewire.platform.limits', $report->build($schedule));
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
