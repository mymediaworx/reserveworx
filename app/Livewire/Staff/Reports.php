<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Location;
use App\Services\Reports\RangeReport;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Reports')]
class Reports extends Component
{
    use WithinTenant;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function channelLabel(string $channel): string
    {
        return match ($channel) {
            'direct' => 'Direct',
            'third_party' => 'Third party',
            default => 'Unrecorded',
        };
    }

    public function render(RangeReport $reports): View
    {
        $window = $reports->window(
            $this->query('from'),
            $this->query('to'),
            $this->query('location'),
            $reports->defaultTimezone(),
        );
        $query = [
            'from' => $window['from'],
            'to' => $window['to'],
        ];

        if ($window['location_id'] !== null) {
            $query['location'] = $window['location_id'];
        }

        return view('livewire.staff.reports', [
            'locations' => Location::query()->orderBy('name')->orderBy('id')->get(),
            'from' => $window['from'],
            'to' => $window['to'],
            'locationId' => $window['location_id'] ?? '',
            'report' => $reports->build($window['from'], $window['to'], $window['location_id']),
            'exportUrl' => '/staff/reports.csv?'.http_build_query($query),
        ]);
    }

    private function query(string $key): string
    {
        $value = request()->query($key);

        return is_string($value) ? $value : '';
    }
}
