<?php

namespace App\Livewire\Platform;

use App\Models\User;
use App\Services\Platform\CrossTenantIsolationCheck;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * On-server cross-tenant review for a platform administrator.
 * Spec Section 12 and Spec Section 17. Opening it does not keep a row.
 */
#[Layout('layouts.platform')]
#[Title('Isolation')]
class Isolation extends Component
{
    public function render(CrossTenantIsolationCheck $check): View
    {
        $this->guard();

        return view('livewire.platform.isolation', $check->build());
    }

    private function guard(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403, 'Platform administrator access is required.');
    }
}
