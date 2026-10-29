<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Support\UpgradeGuide;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Upgrade procedure for a platform administrator. Spec Section 17.
 * Opening it does not deploy and does not write a price, tax, fee, or refund.
 */
#[Layout('layouts.platform')]
#[Title('Upgrade')]
class Upgrade extends Component
{
    public function render(UpgradeGuide $guide): View
    {
        $this->guard();

        return view('livewire.platform.upgrade', [
            'intro' => $guide->intro(),
            'steps' => $guide->steps(),
            'install' => $guide->install(),
        ]);
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
