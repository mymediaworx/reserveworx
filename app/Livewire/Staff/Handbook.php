<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Support\UserHandbook;
use App\Tenancy\Access;
use App\Tenancy\StaffLocationAccess;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Staff how-to for this workspace. Spec Section 17. Opening it writes nothing.
 */
#[Layout('layouts.staff')]
#[Title('Handbook')]
class Handbook extends Component
{
    use WithinTenant;

    public function render(UserHandbook $handbook): View
    {
        $tenant = $this->tenant();
        $user = $this->currentUser();

        $access = app(Access::class);

        return view('livewire.staff.handbook', [
            'workspace' => $tenant->name,
            'sections' => $handbook->forMember(
                $tenant->industry,
                $user,
                $tenant->id,
                $access->hasRole($user, $tenant->id, 'owner', 'manager'),
                app(StaffLocationAccess::class)->isRestricted($user),
                $access->hasRole($user, $tenant->id, 'owner'),
            ),
        ]);
    }
}
