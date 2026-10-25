<?php

namespace App\Services\Schedule;

use App\Enums\BookingStatus;
use App\Enums\ResourceFlag;
use App\Enums\ResourceStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\Service;
use App\Services\Inventory\ClosureCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Resource board for one workspace. Each location uses its own timezone.
 * Holds and allocations already store the buffered interval, so the shaded
 * edges are the minutes outside the booking itself. Moving a block is a
 * separate action on the staff page and still goes through the allocator.
 */
final class ResourceSchedule
{
    public function __construct(private readonly ClosureCalendar $closures) {}

    /**
     * @return array{locations: list<array<string, mixed>>}
     */
    public function present(string $date, string $view, ?string $locationId, ?string $categoryId): array
    {
        $view = $view === 'week' ? 'week' : 'day';
        $locations = Location::query()->orderBy('name')->get();

        if ($locationId !== null && $locationId !== '') {
            $locations = $locations->where('id', $locationId)->values();
        }

        if ($locations->isEmpty()) {
            return ['locations' => []];
        }

        $locationIds = $locations->pluck('id')->all();
        $assets = Asset::query()
            ->whereIn('location_id', $locationIds)
            ->when($categoryId, fn ($query, string $id) => $query->where('resource_category_id', $id))
            ->get();
        $pools = Pool::query()
            ->whereIn('location_id', $locationIds)
            ->when($categoryId, fn ($query, string $id) => $query->where('resource_category_id', $id))
            ->get();

        /** @var array<string, array{start: CarbonImmutable, end: CarbonImmutable, days: list<array<string, mixed>>}> $windows */
        $windows = [];
        $utcStart = null;
        $utcEnd = null;

        foreach ($locations as $location) {
            $window = $this->window($date, $view, $location->timezone);
            $windows[$location->id] = $window;
            $startUtc = $window['start']->utc();
            $endUtc = $window['end']->utc();
            $utcStart = $utcStart === null || $startUtc->lessThan($utcStart) ? $startUtc : $utcStart;
            $utcEnd = $utcEnd === null || $endUtc->greaterThan($utcEnd) ? $endUtc : $utcEnd;
        }

        $entries = $this->entries($assets, $pools, $locations, $utcStart, $utcEnd, $categoryId);
        $categoryNames = ResourceCategory::query()->pluck('name', 'id');
        $closures = LocationClosure::query()->whereIn('location_id', $locationIds)->get()->groupBy('location_id');
        $sections = [];

        foreach ($locations as $location) {
            $window = $windows[$location->id];
            $localEntries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['location_id'] === $location->id
                    && $this->overlaps($entry['occupied_start'], $entry['occupied_end'], $window['start'], $window['end']),
            ));
            $sections[] = $this->section(
                $location,
                $window,
                $localEntries,
                $assets,
                $pools,
                $categoryNames,
                $closures->get($location->id, collect()),
            );
        }

        return ['locations' => $sections];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, days: list<array<string, mixed>>}  $window
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<int, Pool>  $pools
     * @param  Collection<string, string>  $categoryNames
     * @param  Collection<int, LocationClosure>  $closures
     * @return array<string, mixed>
     */
    private function section(Location $location, array $window, array $entries, Collection $assets, Collection $pools, Collection $categoryNames, Collection $closures): array
    {
        $days = $window['days'];
        $summary = $this->row(
            'location:'.$location->id,
            'All bookings',
            '',
            $this->fill($this->collapse($entries), $days, $location->timezone, 'location:'.$location->id, false),
        );
        $summary['shades'] = $this->shades($closures, $location, $days, null, null, null);

        /** @var array<string, array{name: string, rows: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($assets->where('location_id', $location->id)->sortBy(fn (Asset $asset): string => mb_strtolower($asset->name).' '.$asset->id) as $asset) {
            $filled = $this->fill(
                $this->forResource($entries, $asset->id, null),
                $days,
                $location->timezone,
                'asset:'.$asset->id,
                true,
            );

            if ($asset->status === ResourceStatus::Retired && ! $filled['has_blocks']) {
                continue;
            }

            $detail = [];

            if ($asset->is_guide) {
                $detail[] = 'Guide';
            }

            if ($asset->status !== ResourceStatus::Active) {
                $detail[] = ucfirst($asset->status->value);
            }

            $flag = '';

            if ($asset->condition_flag instanceof ResourceFlag) {
                $detail[] = $asset->condition_flag->label();
                $flag = $asset->condition_flag->value;
            }

            $key = $asset->resource_category_id ?? '';
            $groups[$key]['name'] = $key === '' ? 'Uncategorized' : (string) ($categoryNames[$key] ?? 'Uncategorized');
            $row = $this->row('asset:'.$asset->id, $asset->name, implode(' · ', $detail), $filled, $flag);
            $row['shades'] = $this->shades($closures, $location, $days, $asset->resource_category_id, 'asset', $asset->id);
            $groups[$key]['rows'][] = $row;
        }

        foreach ($pools->where('location_id', $location->id)->sortBy(fn (Pool $pool): string => mb_strtolower($pool->name).' '.$pool->id) as $pool) {
            $filled = $this->fill(
                $this->forResource($entries, null, $pool->id),
                $days,
                $location->timezone,
                'pool:'.$pool->id,
                true,
            );

            if ($pool->status === ResourceStatus::Retired && ! $filled['has_blocks']) {
                continue;
            }

            $detail = $pool->quantity.' on hand';

            if ($pool->status !== ResourceStatus::Active) {
                $detail .= ' · '.ucfirst($pool->status->value);
            }

            $flag = '';

            if ($pool->condition_flag instanceof ResourceFlag) {
                $detail .= ' · '.$pool->condition_flag->label();
                $flag = $pool->condition_flag->value;
            }

            $key = $pool->resource_category_id ?? '';
            $groups[$key]['name'] = $key === '' ? 'Uncategorized' : (string) ($categoryNames[$key] ?? 'Uncategorized');
            $row = $this->row('pool:'.$pool->id, $pool->name, $detail, $filled, $flag);
            $row['shades'] = $this->shades($closures, $location, $days, $pool->resource_category_id, 'pool', $pool->id);
            $groups[$key]['rows'][] = $row;
        }

        $groupList = array_values($groups);
        usort($groupList, function (array $left, array $right): int {
            return [$left['name'] === 'Uncategorized', mb_strtolower($left['name'])]
                <=> [$right['name'] === 'Uncategorized', mb_strtolower($right['name'])];
        });

        return [
            'id' => $location->id,
            'name' => $location->name,
            'timezone' => $location->timezone,
            'abbreviation' => $window['start']->format('T'),
            'days' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'weekday' => $day['weekday'],
                'number' => $day['number'],
                'heading' => $day['heading'],
                'today' => $day['today'],
                'minutes' => $day['minutes'],
                'hours' => $day['hours'],
            ], $days),
            'summary' => $summary,
            'groups' => $groupList,
        ];
    }

    /**
     * @param  array{lanes: int, has_blocks: bool, cells: array<string, array{blocks: list<array<string, mixed>>}>}  $filled
     * @return array<string, mixed>
     */
    private function row(string $key, string $name, string $detail, array $filled, string $flag = ''): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'detail' => $detail,
            'flag' => $flag,
            'lanes' => $filled['lanes'],
            'has_blocks' => $filled['has_blocks'],
            'cells' => $filled['cells'],
            'shades' => [],
        ];
    }

    /**
     * Closed time drawn behind the bookings. Location-wide closures shade every
     * row. A category or one resource shades only the rows it covers.
     *
     * @param  Collection<int, LocationClosure>  $closures
     * @param  list<array<string, mixed>>  $days
     * @return array<string, list<array{id: string, reason: string, left: string, width: string}>>
     */
    private function shades(Collection $closures, Location $location, array $days, ?string $categoryId, ?string $type, ?string $id): array
    {
        $cells = [];

        foreach ($days as $day) {
            if (is_string($day['date'] ?? null)) {
                $cells[$day['date']] = [];
            }
        }

        if ($closures->isEmpty() || $days === []) {
            return $cells;
        }

        $first = $days[0]['start'] ?? null;
        $last = $days[count($days) - 1]['end'] ?? null;

        if (! $first instanceof CarbonImmutable || ! $last instanceof CarbonImmutable) {
            return $cells;
        }

        foreach ($closures as $closure) {
            if (! $this->shadeApplies($closure, $categoryId, $type, $id)) {
                continue;
            }

            foreach ($this->closures->windows($closure, $location->timezone, $first->utc(), $last->utc()) as $window) {
                foreach ($days as $day) {
                    $start = $day['start'] ?? null;
                    $end = $day['end'] ?? null;
                    $date = $day['date'] ?? null;

                    if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable || ! is_string($date)) {
                        continue;
                    }

                    $visibleStart = $window[0]->greaterThan($start) ? $window[0] : $start;
                    $visibleEnd = $window[1]->lessThan($end) ? $window[1] : $end;

                    if ($visibleEnd->lessThanOrEqualTo($visibleStart)) {
                        continue;
                    }

                    $dayMinutes = $this->minutes($start, $end);
                    $cells[$date][] = [
                        'id' => $closure->id,
                        'reason' => $closure->reason,
                        'left' => $this->percent($this->minutes($start, $visibleStart), $dayMinutes),
                        'width' => $this->percent($this->minutes($visibleStart, $visibleEnd), $dayMinutes),
                    ];
                }
            }
        }

        return $cells;
    }

    private function shadeApplies(LocationClosure $closure, ?string $categoryId, ?string $type, ?string $id): bool
    {
        $locationWide = ($closure->resource_category_id === null || $closure->resource_category_id === '')
            && ($closure->resource_id === null || $closure->resource_id === '');

        if ($type === null && $id === null) {
            return $locationWide;
        }

        if ($locationWide) {
            return true;
        }

        if ($closure->resource_id !== null && $closure->resource_id !== '') {
            return $closure->resource_type === $type && $closure->resource_id === $id;
        }

        return $closure->resource_category_id !== null && $closure->resource_category_id === $categoryId;
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<int, Pool>  $pools
     * @param  Collection<int, Location>  $locations
     * @return list<array<string, mixed>>
     */
    private function entries(Collection $assets, Collection $pools, Collection $locations, CarbonImmutable $utcStart, CarbonImmutable $utcEnd, ?string $categoryId): array
    {
        $assetsById = $assets->keyBy('id');
        $poolsById = $pools->keyBy('id');
        $locationIds = array_fill_keys($locations->pluck('id')->all(), true);

        $holds = Hold::query()
            ->where('expires_at', '>', now())
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->get();
        $allocations = Allocation::query()
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->get();

        $bookingIds = $holds->pluck('booking_id')
            ->merge($allocations->pluck('booking_id'))
            ->merge(Booking::query()
                ->whereIn('location_id', array_keys($locationIds))
                ->where('starts_at', '<', $utcEnd)
                ->where('ends_at', '>', $utcStart)
                ->where('status', '!=', BookingStatus::Cancelled->value)
                ->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        $bookings = Booking::query()
            ->with(['service', 'customer'])
            ->whereIn('id', $bookingIds)
            ->get()
            ->keyBy('id');

        $entries = [];
        $covered = [];

        foreach ($holds as $hold) {
            $entry = $this->entry($hold->booking_id, 'hold', (int) $hold->quantity, $hold->asset_id, $hold->pool_id, $hold->starts_at, $hold->ends_at, $bookings, $assetsById, $poolsById, $locationIds, $categoryId);

            if ($entry !== null && $entry['booking'] instanceof Booking) {
                $entries[] = $entry;
                $covered[$entry['booking']->id] = true;
            }
        }

        foreach ($allocations as $allocation) {
            $entry = $this->entry($allocation->booking_id, 'allocation', (int) $allocation->quantity, $allocation->asset_id, $allocation->pool_id, $allocation->starts_at, $allocation->ends_at, $bookings, $assetsById, $poolsById, $locationIds, $categoryId);

            if ($entry !== null && $entry['booking'] instanceof Booking) {
                $entries[] = $entry;
                $covered[$entry['booking']->id] = true;
            }
        }

        if ($categoryId !== null && $categoryId !== '') {
            return $entries;
        }

        foreach ($bookings as $booking) {
            if (isset($covered[$booking->id]) || $booking->status === BookingStatus::Held || $booking->status === BookingStatus::Cancelled) {
                continue;
            }

            if (! isset($locationIds[$booking->location_id])) {
                continue;
            }

            $start = $this->instant($booking->starts_at);
            $end = $this->instant($booking->ends_at);

            if ($start === null || $end === null || ! $this->overlaps($start, $end, $utcStart, $utcEnd)) {
                continue;
            }

            $entries[] = [
                'booking' => $booking,
                'kind' => 'allocation',
                'quantity' => 1,
                'occupied_start' => $start,
                'occupied_end' => $end,
                'asset_id' => null,
                'pool_id' => null,
                'location_id' => $booking->location_id,
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<string, Booking>  $bookings
     * @param  Collection<string, Asset>  $assetsById
     * @param  Collection<string, Pool>  $poolsById
     * @param  array<string, true>  $locationIds
     * @return array<string, mixed>|null
     */
    private function entry(string $bookingId, string $kind, int $quantity, ?string $assetId, ?string $poolId, mixed $startsAt, mixed $endsAt, Collection $bookings, Collection $assetsById, Collection $poolsById, array $locationIds, ?string $categoryId): ?array
    {
        $booking = $bookings->get($bookingId);

        if (! $booking instanceof Booking || $booking->status === BookingStatus::Cancelled) {
            return null;
        }

        $start = $this->instant($startsAt);
        $end = $this->instant($endsAt);

        if ($start === null || $end === null) {
            return null;
        }

        $locationId = null;

        if ($assetId !== null && $assetId !== '') {
            $asset = $assetsById->get($assetId);
            $locationId = $asset instanceof Asset ? $asset->location_id : null;
        } elseif ($poolId !== null && $poolId !== '') {
            $pool = $poolsById->get($poolId);
            $locationId = $pool instanceof Pool ? $pool->location_id : null;
        } elseif ($categoryId === null || $categoryId === '') {
            $locationId = $booking->location_id;
            $assetId = null;
            $poolId = null;
        }

        if ($locationId === null || ! isset($locationIds[$locationId])) {
            return null;
        }

        return [
            'booking' => $booking,
            'kind' => $kind,
            'quantity' => max(1, $quantity),
            'occupied_start' => $start,
            'occupied_end' => $end,
            'asset_id' => $assetId !== '' ? $assetId : null,
            'pool_id' => $poolId !== '' ? $poolId : null,
            'location_id' => $locationId,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function forResource(array $entries, ?string $assetId, ?string $poolId): array
    {
        return array_values(array_filter($entries, function (array $entry) use ($assetId, $poolId): bool {
            if ($assetId !== null) {
                return $entry['asset_id'] === $assetId;
            }

            return $entry['pool_id'] === $poolId;
        }));
    }

    /**
     * One block per booking, spanning every resource it holds at this location.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function collapse(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $booking = $entry['booking'];

            if (! $booking instanceof Booking) {
                continue;
            }

            $id = $booking->id;

            if (! isset($grouped[$id])) {
                $grouped[$id] = $entry;
                $grouped[$id]['quantity'] = 1;

                continue;
            }

            if ($entry['occupied_start'] instanceof CarbonImmutable && $entry['occupied_start']->lessThan($grouped[$id]['occupied_start'])) {
                $grouped[$id]['occupied_start'] = $entry['occupied_start'];
            }

            if ($entry['occupied_end'] instanceof CarbonImmutable && $entry['occupied_end']->greaterThan($grouped[$id]['occupied_end'])) {
                $grouped[$id]['occupied_end'] = $entry['occupied_end'];
            }

            if ($entry['kind'] === 'allocation') {
                $grouped[$id]['kind'] = 'allocation';
            }
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $days
     * @return array{lanes: int, has_blocks: bool, cells: array<string, array{blocks: list<array<string, mixed>>}>}
     */
    private function fill(array $entries, array $days, string $zone, string $rowKey, bool $showQuantity): array
    {
        $cells = [];
        $lanes = 1;
        $hasBlocks = false;

        foreach ($days as $day) {
            $start = $day['start'];
            $end = $day['end'];

            if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
                continue;
            }

            $pending = [];

            foreach ($entries as $entry) {
                $occupiedStart = $entry['occupied_start'];
                $occupiedEnd = $entry['occupied_end'];

                if (! $occupiedStart instanceof CarbonImmutable || ! $occupiedEnd instanceof CarbonImmutable) {
                    continue;
                }

                if ($this->overlaps($occupiedStart, $occupiedEnd, $start, $end)) {
                    $pending[] = $entry;
                }
            }

            usort($pending, function (array $left, array $right): int {
                $leftStart = $left['occupied_start'] instanceof CarbonImmutable ? $left['occupied_start']->getTimestamp() : 0;
                $rightStart = $right['occupied_start'] instanceof CarbonImmutable ? $right['occupied_start']->getTimestamp() : 0;
                $byTime = $leftStart <=> $rightStart;

                if ($byTime !== 0) {
                    return $byTime;
                }

                $leftId = $left['booking'] instanceof Booking ? $left['booking']->id : '';
                $rightId = $right['booking'] instanceof Booking ? $right['booking']->id : '';

                return strcmp($leftId, $rightId);
            });

            $laneEnds = [];
            $blocks = [];

            foreach ($pending as $entry) {
                $visibleStart = $entry['occupied_start']->greaterThan($start) ? $entry['occupied_start'] : $start;
                $visibleEnd = $entry['occupied_end']->lessThan($end) ? $entry['occupied_end'] : $end;
                $lane = null;

                foreach ($laneEnds as $index => $laneEnd) {
                    if ($visibleStart->getTimestamp() >= $laneEnd) {
                        $lane = $index;
                        break;
                    }
                }

                if ($lane === null) {
                    $lane = count($laneEnds);
                }

                $laneEnds[$lane] = $visibleEnd->getTimestamp();
                $block = $this->block($entry, $start, $end, $zone, $rowKey, $lane, $showQuantity);

                if ($block !== null) {
                    $blocks[] = $block;
                    $hasBlocks = true;
                }
            }

            $lanes = max($lanes, count($laneEnds) > 0 ? count($laneEnds) : 1);
            $cells[$day['date']] = ['blocks' => $blocks];
        }

        return ['lanes' => $lanes, 'has_blocks' => $hasBlocks, 'cells' => $cells];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function block(array $entry, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $zone, string $rowKey, int $lane, bool $showQuantity): ?array
    {
        $booking = $entry['booking'];
        $occupiedStart = $entry['occupied_start'];
        $occupiedEnd = $entry['occupied_end'];

        if (! $booking instanceof Booking || ! $occupiedStart instanceof CarbonImmutable || ! $occupiedEnd instanceof CarbonImmutable) {
            return null;
        }

        $bookStart = $this->instant($booking->starts_at);
        $bookEnd = $this->instant($booking->ends_at);

        if ($bookStart === null || $bookEnd === null) {
            return null;
        }

        $visibleStart = $occupiedStart->greaterThan($dayStart) ? $occupiedStart : $dayStart;
        $visibleEnd = $occupiedEnd->lessThan($dayEnd) ? $occupiedEnd : $dayEnd;

        if ($visibleEnd->lessThanOrEqualTo($visibleStart)) {
            return null;
        }

        $dayMinutes = $this->minutes($dayStart, $dayEnd);
        $visibleMinutes = $this->minutes($visibleStart, $visibleEnd);
        $coreStart = $bookStart->max($visibleStart);
        $coreEnd = $bookEnd->min($visibleEnd);
        $before = 0.0;
        $after = 0.0;

        if ($coreEnd->lessThanOrEqualTo($coreStart)) {
            $before = $visibleMinutes;
        } else {
            if ($coreStart->greaterThan($visibleStart)) {
                $before = $this->minutes($visibleStart, $coreStart);
            }

            if ($visibleEnd->greaterThan($coreEnd)) {
                $after = $this->minutes($coreEnd, $visibleEnd);
            }
        }

        $localStart = $bookStart->timezone($zone);
        $localEnd = $bookEnd->timezone($zone);
        $customer = $booking->customer;
        $who = 'Walk-in';

        if ($customer instanceof Customer) {
            if ($customer->name !== '') {
                $who = $customer->name;
            } elseif ($customer->email !== null && $customer->email !== '') {
                $who = $customer->email;
            }
        }

        $quantity = (int) $entry['quantity'];

        if ($showQuantity && $quantity > 1) {
            $who .= ' × '.$quantity;
        }

        $service = $booking->service instanceof Service ? $booking->service->name : 'Booking';
        $prep = $bookStart->greaterThan($occupiedStart) ? $this->wholeMinutes($occupiedStart, $bookStart) : 0;
        $tail = $occupiedEnd->greaterThan($bookEnd) ? $this->wholeMinutes($bookEnd, $occupiedEnd) : 0;
        $kind = $entry['kind'] === 'hold' ? 'hold' : 'allocation';
        $title = ($kind === 'hold' ? 'Hold' : 'Booking').', '.$who.', '.$service.', '.$localStart->format('g:i A').' – '.$localEnd->format('g:i A');

        if ($prep > 0) {
            $title .= ', '.$prep.' minute buffer before';
        }

        if ($tail > 0) {
            $title .= ', '.$tail.' minute buffer after';
        }

        return [
            'booking_id' => $booking->id,
            'href' => '/staff/bookings/'.$booking->id,
            'row' => $rowKey,
            'kind' => $kind,
            'status' => $booking->status->value,
            'lane' => $lane,
            'day' => $dayStart->toDateString(),
            'left' => $this->percent($this->minutes($dayStart, $visibleStart), $dayMinutes),
            'width' => $this->percent($visibleMinutes, $dayMinutes),
            'buffer_before' => $this->percent($before, $visibleMinutes),
            'buffer_after' => $this->percent($after, $visibleMinutes),
            'local_start' => $localStart->format('Y-m-d\TH:i'),
            'local_end' => $localEnd->format('Y-m-d\TH:i'),
            'occupied_start' => $occupiedStart->timezone($zone)->format('Y-m-d\TH:i'),
            'occupied_end' => $occupiedEnd->timezone($zone)->format('Y-m-d\TH:i'),
            'who' => $who,
            'time' => $localStart->format('g:i').'–'.$localEnd->format('g:i A'),
            'title' => $title,
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, days: list<array<string, mixed>>}
     */
    private function window(string $date, string $view, string $zone): array
    {
        $anchor = $this->anchor($date, $zone);
        $first = $view === 'week' ? $anchor->startOfWeek(CarbonImmutable::MONDAY) : $anchor;
        $count = $view === 'week' ? 7 : 1;
        $days = [];

        for ($index = 0; $index < $count; $index++) {
            $start = $first->addDays($index)->startOfDay();
            $end = $start->addDay()->startOfDay();
            $minutes = $this->minutes($start, $end);
            $hours = [];
            $cursor = $start;

            for ($guard = 0; $guard < 30 && $cursor < $end; $guard++) {
                $hours[] = [
                    'label' => $cursor->format('ga'),
                    'start' => $cursor->format('H:i'),
                    'left' => $this->percent($this->minutes($start, $cursor), $minutes),
                ];
                $cursor = $cursor->addHour();
            }

            $lastHour = count($hours) - 1;

            foreach ($hours as $hourIndex => $hour) {
                $next = $hourIndex === $lastHour ? 100.0 : (float) $hours[$hourIndex + 1]['left'];
                $hours[$hourIndex]['width'] = number_format($next - (float) $hour['left'], 3, '.', '');
            }

            $days[] = [
                'date' => $start->toDateString(),
                'weekday' => $start->format('D'),
                'number' => $start->format('j'),
                'heading' => $start->format('l, F j'),
                'today' => $start->isSameDay(CarbonImmutable::now($zone)),
                'minutes' => (int) round($minutes),
                'hours' => $hours,
                'start' => $start,
                'end' => $end,
            ];
        }

        return [
            'start' => $days[0]['start'],
            'end' => $days[$count - 1]['end'],
            'days' => $days,
        ];
    }

    private function anchor(string $date, string $zone): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if ($parsed instanceof CarbonImmutable) {
            return $parsed->startOfDay();
        }

        return CarbonImmutable::now($zone)->startOfDay();
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return null;
    }

    private function overlaps(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): bool
    {
        return $start->getTimestamp() < $windowEnd->getTimestamp()
            && $end->getTimestamp() > $windowStart->getTimestamp();
    }

    private function minutes(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return abs($start->diffInMinutes($end));
    }

    private function wholeMinutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round($this->minutes($start, $end));
    }

    private function percent(float $part, float $whole): string
    {
        if ($whole <= 0.0) {
            return '0.000';
        }

        return number_format($part / $whole * 100, 3, '.', '');
    }
}
