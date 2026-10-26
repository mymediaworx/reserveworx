<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Services\Dispatch\DispatchManifest;
use App\Services\Fleet\FleetLoadSheet;
use App\Tenancy\StaffLocationAccess;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Daily load sheet')]
class LoadSheet extends Component
{
    use WithinTenant;

    #[Url]
    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->allows('bookings.view') || $this->allows('inventory.view'), 403);

        if (! DispatchManifest::validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        }
    }

    public function shift(int $direction): void
    {
        $this->date = $this->day()->addDays($direction > 0 ? 1 : -1)->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
    }

    public function render(FleetLoadSheet $sheet): View
    {
        $restricted = app(StaffLocationAccess::class)->isRestricted($this->currentUser());

        return view('livewire.staff.load-sheet', [
            'sheet' => $sheet->forDate($this->date, $restricted),
            'zone' => $this->defaultTimezone(),
        ]);
    }

    private function day(): CarbonImmutable
    {
        $date = DispatchManifest::validDate($this->date)
            ? $this->date
            : CarbonImmutable::now($this->defaultTimezone())->toDateString();

        return CarbonImmutable::parse($date, $this->defaultTimezone())->startOfDay();
    }
}
