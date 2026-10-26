<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Enums\MembershipStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceType;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Pool;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\TourMinimum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Calendar')]
class Calendar extends Component
{
    use LoadsBookingRows, WithinTenant;

    private const VIEWS = ['day', 'week', 'month', 'agenda'];

    #[Url]
    public string $date = '';

    #[Url]
    public string $view = 'week';

    #[Url]
    public string $location = '';

    #[Url]
    public string $service = '';

    #[Url(except: '')]
    public string $resource = '';

    #[Url(except: '')]
    public string $staff = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payment = '';

    #[Url(except: '')]
    public string $operational = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
        $this->prepareFilters();
    }

    public function updatedLocation(): void
    {
        $this->clearFiltersOutsideLocation();
    }

    public function shift(int $direction): void
    {
        $this->prepareFilters();
        $step = $direction > 0 ? 1 : -1;
        $next = match ($this->view) {
            'day' => $this->day()->addDays($step),
            'month' => $this->day()->addMonthsNoOverflow($step),
            default => $this->day()->addDays($step * 7),
        };
        $this->date = $next->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
    }

    public function render(TourMinimum $minimum): View
    {
        $this->prepareFilters();
        $zone = $this->defaultTimezone();
        $bounds = $this->bounds();
        $bookings = $this->bookings($bounds['start'], $bounds['end']);
        $departures = $this->departures($bounds['start'], $bounds['end']);
        $summary = $minimum->summarize($departures, CarbonImmutable::now($zone));
        $days = $this->days($bounds['dates'], $bounds['month'], $bookings, $departures, $summary, $zone);

        return view('livewire.staff.calendar', [
            'zone' => $zone,
            'days' => $days,
            'weeks' => $this->view === 'month' ? array_chunk($days, 7) : [],
            'first' => $bounds['labelStart'],
            'last' => $bounds['labelEnd'],
            'weekdayLabels' => $this->weekdayLabels($bounds['dates']),
            'customers' => $this->customersFor($bookings),
            'zones' => $this->zones(),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'services' => $this->services(),
            'assets' => $this->assets(),
            'pools' => $this->pools(),
            'staffMembers' => $this->staffMembers(),
            'bookingStatuses' => $this->statusValues(BookingStatus::cases()),
            'paymentStatuses' => $this->statusValues(PaymentStatus::cases()),
            'operationalStatuses' => $this->statusValues(OperationalStatus::cases()),
            'todayDate' => CarbonImmutable::now($zone)->toDateString(),
            'keptQuery' => $this->keptQuery(),
        ]);
    }

    private function prepareFilters(): void
    {
        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        }

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'week';
        }

        $this->status = $this->enumValue($this->status, BookingStatus::class);
        $this->payment = $this->enumValue($this->payment, PaymentStatus::class);
        $this->operational = $this->enumValue($this->operational, OperationalStatus::class);

        if ($this->location !== '' && ! Location::query()->whereKey($this->location)->exists()) {
            abort(404);
        }

        $this->guardChoice($this->service, Service::class);
        $this->guardResource();
        $this->guardStaff();
        $this->clearFiltersOutsideLocation();
    }

    private function clearFiltersOutsideLocation(): void
    {
        if ($this->location === '') {
            return;
        }

        if ($this->service !== '') {
            $matches = Service::query()->whereKey($this->service)->where('location_id', $this->location)->exists();

            if (! $matches) {
                $this->service = '';
            }
        }

        $parsed = $this->parsedResource();

        if ($parsed === null) {
            return;
        }

        $model = $this->resourceModel($parsed['type'], $parsed['id']);

        if (! $model instanceof Model || $model->getAttribute('location_id') !== $this->location) {
            $this->resource = '';
        }
    }

    /**
     * @param  class-string<Service>  $model
     */
    private function guardChoice(string $id, string $model): void
    {
        if ($id === '') {
            return;
        }

        abort_unless($model::query()->whereKey($id)->exists(), 404);
    }

    private function guardResource(): void
    {
        $parsed = $this->parsedResource();

        if ($parsed === null) {
            return;
        }

        abort_unless($this->resourceModel($parsed['type'], $parsed['id']) instanceof Model, 404);
    }

    private function guardStaff(): void
    {
        if ($this->staff === '') {
            return;
        }

        $member = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->where('user_id', $this->staff)
            ->exists();

        abort_unless($member, 404);
    }

    /**
     * @return array{type: string, id: string}|null
     */
    private function parsedResource(): ?array
    {
        if ($this->resource === '') {
            return null;
        }

        if (preg_match('/^(asset|pool):([0-9A-Za-z]{26})$/', $this->resource, $match) !== 1) {
            abort(404);
        }

        return ['type' => $match[1], 'id' => $match[2]];
    }

    private function resourceModel(string $type, string $id): ?Model
    {
        $model = $type === 'asset' ? Asset::query()->find($id) : Pool::query()->find($id);

        return $model instanceof Model ? $model : null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, dates: list<CarbonImmutable>, month: ?CarbonImmutable, labelStart: CarbonImmutable, labelEnd: CarbonImmutable}
     */
    private function bounds(): array
    {
        $anchor = $this->day();

        if ($this->view === 'day') {
            return $this->window($anchor, $anchor, null, $anchor, $anchor);
        }

        if ($this->view === 'month') {
            $monthStart = $anchor->startOfMonth();
            $gridStart = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $dates = [];
            $cursor = $gridStart;

            while (count($dates) < 42) {
                $dates[] = $cursor;
                $passedEnd = $cursor->greaterThanOrEqualTo($monthEnd);
                $cursor = $cursor->addDay();

                if ($passedEnd && count($dates) % 7 === 0) {
                    break;
                }
            }

            $last = $dates[array_key_last($dates)];

            return $this->window($dates[0], $last, $monthStart, $monthStart, $monthEnd);
        }

        if ($this->view === 'agenda') {
            return $this->window($anchor, $anchor->addDays(6), null, $anchor, $anchor->addDays(6));
        }

        $start = $anchor->startOfWeek(CarbonImmutable::MONDAY);

        return $this->window($start, $start->addDays(6), null, $start, $start->addDays(6));
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, dates: list<CarbonImmutable>, month: ?CarbonImmutable, labelStart: CarbonImmutable, labelEnd: CarbonImmutable}
     */
    private function window(
        CarbonImmutable $first,
        CarbonImmutable $last,
        ?CarbonImmutable $month,
        CarbonImmutable $labelStart,
        CarbonImmutable $labelEnd,
    ): array {
        $dates = [];
        $cursor = $first->startOfDay();
        $endDay = $last->startOfDay();

        while ($cursor->lessThanOrEqualTo($endDay)) {
            $dates[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return [
            'start' => $first->startOfDay()->utc(),
            'end' => $last->startOfDay()->addDay()->utc(),
            'dates' => $dates,
            'month' => $month,
            'labelStart' => $labelStart,
            'labelEnd' => $labelEnd,
        ];
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookings(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = Booking::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->orderBy('id');
        $this->constrainBookings($query);

        return $query->get();
    }

    /**
     * @return Collection<int, Departure>
     */
    private function departures(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = Departure::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($this->location !== '', fn (Builder $query) => $query->where('location_id', $this->location))
            ->when($this->service !== '', fn (Builder $query) => $query->where('service_id', $this->service))
            ->orderBy('starts_at')
            ->orderBy('id');
        $this->constrainDepartures($query);

        return $query->get();
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function constrainBookings(Builder $query): void
    {
        $query
            ->when($this->location !== '', fn (Builder $query) => $query->where('location_id', $this->location))
            ->when($this->service !== '', fn (Builder $query) => $query->where('service_id', $this->service))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->payment !== '', fn (Builder $query) => $query->where('payment_status', $this->payment))
            ->when($this->operational !== '', fn (Builder $query) => $query->where('operational_status', $this->operational));
        $this->constrainStaff($query);
        $this->constrainResource($query);
    }

    /**
     * @param  Builder<Departure>  $query
     */
    private function constrainDepartures(Builder $query): void
    {
        $bookingLevel = $this->status !== '' || $this->payment !== '' || $this->operational !== '' || $this->staff !== '';
        $parsed = $this->parsedResource();

        if (! $bookingLevel && $parsed === null) {
            return;
        }

        $query->where(function (Builder $outer) use ($bookingLevel, $parsed): void {
            $outer->whereHas('bookings', function (Builder $bookings) use ($parsed): void {
                $bookings
                    ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
                    ->when($this->payment !== '', fn (Builder $query) => $query->where('payment_status', $this->payment))
                    ->when($this->operational !== '', fn (Builder $query) => $query->where('operational_status', $this->operational));
                $this->constrainStaff($bookings);

                if ($parsed !== null) {
                    $this->constrainResource($bookings);
                }
            });

            if (! $bookingLevel) {
                $type = ResourceType::from($parsed['type']);
                $id = $parsed['id'];
                $outer->orWhereHas('service.requirements', function (Builder $requirements) use ($type, $id): void {
                    $requirements->where('resource_type', $type)->where('resource_id', $id);
                });
            }
        });
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function constrainStaff(Builder $query): void
    {
        if ($this->staff === '') {
            return;
        }

        $staff = $this->staff;
        $query->where(function (Builder $inner) use ($staff): void {
            $inner->where('guide_user_id', $staff)->orWhere('created_by', $staff);
        });
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function constrainResource(Builder $query): void
    {
        $parsed = $this->parsedResource();

        if ($parsed === null) {
            return;
        }

        $column = $parsed['type'] === 'asset' ? 'asset_id' : 'pool_id';
        $id = $parsed['id'];
        $query->where(function (Builder $inner) use ($column, $id): void {
            $inner->whereHas('allocations', fn (Builder $rows): Builder => $rows->where($column, $id))
                ->orWhereHas('holds', fn (Builder $rows): Builder => $rows->where($column, $id));
        });
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @param  Collection<int, Booking>  $bookings
     * @param  Collection<int, Departure>  $departures
     * @param  array<string, array{participants: int, minimum: int|null, belowMinimum: bool}>  $summary
     * @return list<array{date: CarbonImmutable, inMonth: bool, bookings: Collection<int, Booking>, departures: list<array{departure: Departure, participants: int, minimum: int|null, belowMinimum: bool}>}>
     */
    private function days(
        array $dates,
        ?CarbonImmutable $month,
        Collection $bookings,
        Collection $departures,
        array $summary,
        string $zone,
    ): array {
        $days = [];

        foreach ($dates as $date) {
            $key = $date->toDateString();
            $rows = [];

            foreach ($departures as $departure) {
                if (CarbonImmutable::instance($departure->starts_at)->timezone($zone)->toDateString() !== $key) {
                    continue;
                }

                $fact = $summary[$departure->id] ?? ['participants' => 0, 'minimum' => null, 'belowMinimum' => false];
                $rows[] = [
                    'departure' => $departure,
                    'participants' => $fact['participants'],
                    'minimum' => $fact['minimum'],
                    'belowMinimum' => $fact['belowMinimum'],
                ];
            }

            $days[] = [
                'date' => $date,
                'inMonth' => $month === null || ($date->month === $month->month && $date->year === $month->year),
                'bookings' => $bookings
                    ->filter(fn (Booking $booking): bool => CarbonImmutable::instance($booking->starts_at)->timezone($zone)->toDateString() === $key)
                    ->values(),
                'departures' => $rows,
            ];
        }

        return $days;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @return list<string>
     */
    private function weekdayLabels(array $dates): array
    {
        $labels = [];

        foreach (array_slice($dates, 0, 7) as $date) {
            $labels[] = $date->format('D');
        }

        return $labels;
    }

    /**
     * @return Collection<int, Service>
     */
    private function services(): Collection
    {
        return Service::query()
            ->when($this->location !== '', fn (Builder $query) => $query->where('location_id', $this->location))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assets(): Collection
    {
        return Asset::query()
            ->when($this->location !== '', fn (Builder $query) => $query->where('location_id', $this->location))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'location_id']);
    }

    /**
     * @return Collection<int, Pool>
     */
    private function pools(): Collection
    {
        return Pool::query()
            ->when($this->location !== '', fn (Builder $query) => $query->where('location_id', $this->location))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'location_id']);
    }

    /**
     * @return Collection<int, User>
     */
    private function staffMembers(): Collection
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    /**
     * @param  list<BookingStatus|PaymentStatus|OperationalStatus>  $cases
     * @return list<string>
     */
    private function statusValues(array $cases): array
    {
        return array_map(fn (BookingStatus|PaymentStatus|OperationalStatus $status): string => $status->value, $cases);
    }

    /**
     * @param  class-string<BookingStatus|PaymentStatus|OperationalStatus>  $enum
     */
    private function enumValue(string $value, string $enum): string
    {
        if ($value === '') {
            return '';
        }

        $case = $enum::tryFrom($value);

        return $case !== null ? $case->value : '';
    }

    /**
     * Filters that stay in links to a single day or the manifest.
     */
    private function keptQuery(): string
    {
        $query = array_filter([
            'location' => $this->location,
            'service' => $this->service,
            'resource' => $this->resource,
            'staff' => $this->staff,
            'status' => $this->status,
            'payment' => $this->payment,
            'operational' => $this->operational,
        ], fn (string $value): bool => $value !== '');

        return $query === [] ? '' : '&'.http_build_query($query);
    }

    private function day(): CarbonImmutable
    {
        $zone = $this->defaultTimezone();
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
