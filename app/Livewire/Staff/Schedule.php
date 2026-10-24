<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Location;
use App\Models\ResourceCategory;
use App\Services\Schedule\ResourceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Schedule')]
class Schedule extends Component
{
    use WithinTenant;

    #[Url]
    public string $date = '';

    #[Url]
    public string $view = 'day';

    #[Url]
    public string $location = '';

    #[Url]
    public string $category = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if (! in_array($this->view, ['day', 'week'], true)) {
            $this->view = 'day';
        }

        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
        }
    }

    public function shift(int $direction): void
    {
        $step = $this->view === 'day' ? 1 : 7;
        $this->date = $this->day()->addDays($direction > 0 ? $step : -$step)->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
    }

    public function render(): View
    {
        $zone = $this->activeTimezone();
        $first = $this->view === 'day' ? $this->day() : $this->day()->startOfWeek(CarbonImmutable::MONDAY);
        $days = $this->view === 'day' ? 1 : 7;

        return view('livewire.staff.schedule', [
            'board' => app(ResourceSchedule::class)->present(
                $this->day()->toDateString(),
                $this->view,
                $this->location !== '' ? $this->location : null,
                $this->category !== '' ? $this->category : null,
            ),
            'zone' => $zone,
            'first' => $first,
            'last' => $first->addDays($days - 1),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ResourceCategory::query()->orderBy('name')->get(['id', 'name']),
            'todayDate' => CarbonImmutable::now($zone)->toDateString(),
        ]);
    }

    private function activeTimezone(): string
    {
        if ($this->location !== '') {
            $zone = Location::query()->whereKey($this->location)->value('timezone');

            if (is_string($zone) && $zone !== '') {
                return $zone;
            }
        }

        return $this->defaultTimezone();
    }

    private function day(): CarbonImmutable
    {
        $zone = $this->activeTimezone();
        $parsed = $this->validDate($this->date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->date, $zone)
            : false;

        return $parsed instanceof CarbonImmutable
            ? $parsed->startOfDay()
            : CarbonImmutable::now($zone)->startOfDay();
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
