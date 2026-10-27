<?php

namespace App\Services\Dispatch;

use App\Enums\BookingStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Pool;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\CheckIn\BalanceDue;
use App\Services\CheckIn\WaiverGate;
use App\Support\Display;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * The day's confirmed and no-show bookings, grouped by location and ordered
 * by the start in that location's timezone. Held and cancelled bookings stay off.
 */
final class DispatchManifest
{
    public function __construct(
        private BalanceDue $balances,
        private WaiverGate $waivers,
    ) {}

    public static function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @return list<array{id: string, name: string, timezone: string, bookings: list<ManifestBooking>}>
     */
    public function sections(string $date, Collection $locations): array
    {
        if (! self::validDate($date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        /** @var list<array{location: Location, zone: string, bookings: Collection<int, Booking>}> $grouped */
        $grouped = [];
        $all = new Collection;

        foreach ($locations as $location) {
            $zone = $this->zone($location);
            [$start, $end] = $this->window($date, $zone);
            $bookings = Booking::query()
                ->with(['service', 'customer', 'guide', 'namedParticipants', 'addOns', 'allocations'])
                ->where('location_id', $location->id)
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
                ->where('starts_at', '>=', $start->utc())
                ->where('starts_at', '<', $end->utc())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();
            $grouped[] = ['location' => $location, 'zone' => $zone, 'bookings' => $bookings];
            $all = $all->concat($bookings);
        }

        $balances = $this->balances->forBookings($all);
        $current = $this->waivers->current();
        $ids = [];

        foreach ($all as $booking) {
            $ids[] = $booking->id;
        }

        $signatures = $ids === []
            ? new Collection
            : WaiverSignature::query()->whereIn('booking_id', $ids)->get()->groupBy('booking_id');
        $assets = $this->names(Asset::class, $this->resourceIds($all, 'asset_id'));
        $pools = $this->names(Pool::class, $this->resourceIds($all, 'pool_id'));
        $sections = [];

        foreach ($grouped as $group) {
            $rows = [];

            foreach ($group['bookings'] as $booking) {
                $rows[] = $this->row($booking, $group['zone'], $balances, $current, $signatures, $assets, $pools);
            }

            $sections[] = [
                'id' => $group['location']->id,
                'name' => $group['location']->name,
                'timezone' => $group['zone'],
                'bookings' => $rows,
            ];
        }

        return $sections;
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    public function toCsv(string $date, Collection $locations): string
    {
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the manifest.');
        }

        fputcsv($handle, [
            'location',
            'timezone',
            'starts_at',
            'return_due',
            'booking_id',
            'service',
            'resource',
            'guide',
            'customer_name',
            'customer_phone',
            'party_size',
            'participants',
            'waivers',
            'add_ons',
            'balance_due_cents',
            'currency',
            'check_in',
            'staff_notes',
        ]);

        foreach ($this->sections($date, $locations) as $section) {
            foreach ($section['bookings'] as $booking) {
                fputcsv($handle, [
                    $section['name'],
                    $section['timezone'],
                    $booking->localStart(),
                    $booking->localReturn(),
                    $booking->id,
                    $booking->service,
                    $booking->resource,
                    $booking->guideName,
                    $booking->customerName,
                    $booking->customerPhone,
                    $booking->partySize,
                    $booking->participantSummary(),
                    $booking->waiverSummary(),
                    $booking->addOns,
                    $booking->balanceCents === null ? '' : (string) $booking->balanceCents,
                    $booking->currency,
                    $booking->checkIn,
                    $booking->staffNotes ?? '',
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param  array<string, array{cents: int|null, currency: string}>  $balances
     * @param  Collection<string, WaiverVersion>  $current
     * @param  Collection<string, Collection<int, WaiverSignature>>  $signatures
     * @param  Collection<string, string>  $assets
     * @param  Collection<string, string>  $pools
     */
    private function row(
        Booking $booking,
        string $zone,
        array $balances,
        Collection $current,
        Collection $signatures,
        Collection $assets,
        Collection $pools,
    ): ManifestBooking {
        $customer = $booking->customer;
        $signatureRows = $signatures->get($booking->id);
        $balance = $balances[$booking->id] ?? ['cents' => null, 'currency' => 'USD'];
        $notes = is_string($booking->staff_notes) ? trim($booking->staff_notes) : '';

        return new ManifestBooking(
            id: $booking->id,
            service: $booking->service->name ?? 'Booking',
            startsAt: CarbonImmutable::parse($booking->starts_at)->utc(),
            returnDue: CarbonImmutable::parse($booking->ends_at)->utc(),
            resource: $this->resource($booking, $assets, $pools),
            guideName: $booking->guide instanceof User ? $booking->guide->name : '',
            customerName: $customer instanceof Customer && $customer->name !== '' ? $customer->name : 'Walk-in',
            customerPhone: $customer instanceof Customer && is_string($customer->phone) ? $customer->phone : '',
            partySize: (int) $booking->participants,
            people: $this->waivers->lines(
                $booking->namedParticipants,
                $signatureRows instanceof Collection ? $signatureRows : new Collection,
                $current,
            ),
            addOns: $this->addOns($booking),
            balanceCents: $balance['cents'],
            currency: $balance['currency'],
            checkIn: $this->checkIn($booking),
            staffNotes: $notes === '' ? null : $notes,
            timezone: $zone,
        );
    }

    /**
     * @param  Collection<string, string>  $assets
     * @param  Collection<string, string>  $pools
     */
    private function resource(Booking $booking, Collection $assets, Collection $pools): string
    {
        $parts = [];

        foreach ($booking->allocations->sortBy('id') as $allocation) {
            if (is_string($allocation->asset_id) && $allocation->asset_id !== '') {
                $name = $assets->get($allocation->asset_id);
                $parts[] = is_string($name) && $name !== '' ? $name : 'Asset';

                continue;
            }

            if (is_string($allocation->pool_id) && $allocation->pool_id !== '') {
                $name = $pools->get($allocation->pool_id);
                $label = is_string($name) && $name !== '' ? $name : 'Pool';
                $quantity = (int) $allocation->quantity;
                $parts[] = $quantity > 1 ? $label.' × '.$quantity : $label;
            }
        }

        return $parts === [] ? 'Unassigned' : implode(', ', $parts);
    }

    private function addOns(Booking $booking): string
    {
        $parts = [];

        foreach ($booking->addOns as $addOn) {
            $quantity = (int) $addOn->quantity;
            $parts[] = $quantity > 1 ? $addOn->name.' × '.$quantity : $addOn->name;
        }

        return implode(', ', $parts);
    }

    private function checkIn(Booking $booking): string
    {
        if ($booking->status === BookingStatus::NoShow) {
            return 'No show';
        }

        return Display::status($booking->operational_status->value);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<string>
     */
    private function resourceIds(Collection $bookings, string $column): array
    {
        $ids = [];

        foreach ($bookings as $booking) {
            foreach ($booking->allocations as $allocation) {
                $id = $allocation->getAttribute($column);

                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  class-string<Asset|Pool>  $model
     * @param  list<string>  $ids
     * @return Collection<string, string>
     */
    private function names(string $model, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<string, string> $names */
        $names = $model::query()->whereIn('id', $ids)->pluck('name', 'id');

        return $names;
    }

    private function zone(Location $location): string
    {
        return DisplayZone::forLocation($location);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $date, string $zone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if (! $start instanceof CarbonImmutable) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        $start = $start->startOfDay();

        return [$start, $start->addDay()];
    }
}
