<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\ReadinessReport;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.platform')]
#[Title('Readiness')]
class Readiness extends Component
{
    public function render(ReadinessReport $report): View
    {
        $this->guard();

        return view('livewire.platform.readiness', $report->build());
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
