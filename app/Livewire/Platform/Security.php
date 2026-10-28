<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\SecurityCheck;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.platform')]
#[Title('Security')]
class Security extends Component
{
    public function render(SecurityCheck $check): View
    {
        $this->guard();

        return view('livewire.platform.security', $check->build());
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
