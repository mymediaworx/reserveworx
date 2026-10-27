<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\PerformanceCheck;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.platform')]
#[Title('Performance')]
class Performance extends Component
{
    public function render(PerformanceCheck $check): View
    {
        $this->guard();

        return view('livewire.platform.performance', $check->build());
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
