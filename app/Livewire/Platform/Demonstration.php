<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\TenantDemonstration;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Multi-tenant demonstration for a platform administrator. Spec Section 17.
 * Opening it does not create a workspace and does not write a price, tax, fee, or refund.
 */
#[Layout('layouts.platform')]
#[Title('Demo')]
class Demonstration extends Component
{
    public function render(TenantDemonstration $demonstration): View
    {
        $this->guard();

        return view('livewire.platform.demonstration', $demonstration->build());
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
