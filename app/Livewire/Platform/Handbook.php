<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Support\UserHandbook;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Platform administrator how-to. Spec Section 17. Opening it writes nothing.
 */
#[Layout('layouts.platform')]
#[Title('Admin handbook')]
class Handbook extends Component
{
    public function render(UserHandbook $handbook): View
    {
        $this->guard();

        return view('livewire.platform.handbook', [
            'sections' => $handbook->platform(),
        ]);
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
