<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\CsvImport;
use App\Tenancy\StaffLocationAccess;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The phone's "Menu" tab: every tool in one place. Desktop shows the same
 * items in the side navigation.
 */
#[Layout('layouts.staff')]
#[Title('Menu')]
class Menu extends Component
{
    use WithinTenant;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function render(): View
    {
        return view('livewire.staff.menu', [
            'user' => $this->currentUser(),
            'canManageTeam' => $this->allows('members.manage'),
            'canImport' => $this->currentUser()->can('viewAny', CsvImport::class),
            'canPrivacy' => $this->allows('customers.privacy')
                && ! app(StaffLocationAccess::class)->isRestricted($this->currentUser()),
        ]);
    }
}
