<?php

namespace App\Services\Search;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Pool;
use App\Models\StaffSearchToken;
use App\Support\Display;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Staff quick search. Token lookups are prefix matches on
 * staff_search_token_idx or staff_search_location_token_idx. A second query
 * pages the matching rows. Each word keeps at most {@see self::ID_CAP} ids.
 */
final class StaffQuickSearch
{
    public const PER_PAGE = 8;

    public const ID_CAP = 300;

    public function __construct(private readonly StaffSearchIndex $index) {}

    /**
     * @return list<QuickSearchGroup>
     */
    public function groups(string $query, int $bookingsPage, int $customersPage, int $inventoryPage, bool $bookings, bool $inventory): array
    {
        $terms = $this->index->terms($query);

        if ($terms === []) {
            return [];
        }

        $locations = app(StaffLocationAccess::class)->restrictedLocationIds();
        $groups = [];

        if ($bookings) {
            $groups[] = $this->bookings($terms, $locations, $bookingsPage);
            $groups[] = $this->customers($terms, $locations, $customersPage);
        }

        if ($inventory) {
            $groups[] = $this->inventory($terms, $locations, $inventoryPage);
        }

        return $groups;
    }

    public function hasTerms(string $query): bool
    {
        return $this->index->terms($query) !== [];
    }

    /**
     * The indexed lookup used for one word. Location ids null means every
     * location the membership can already see through the tenant scope.
     *
     * @param  list<string>|null  $locationIds
     * @return Builder<StaffSearchToken>
     */
    public function tokenLookup(string $type, string $term, ?array $locationIds): Builder
    {
        $query = StaffSearchToken::query()
            ->select('subject_id')
            ->where('subject_type', $type)
            ->where('token', 'like', $this->prefix($term));

        if ($locationIds !== null) {
            $query->whereIn('location_id', $locationIds === [] ? [''] : $locationIds);
        }

        return $query;
    }

    /**
     * Bookings for a customer id list. Tenant and location scopes stay on.
     *
     * @param  list<string>  $customerIds
     * @return Builder<Booking>
     */
    public function bookingsForCustomers(array $customerIds): Builder
    {
        return Booking::query()
            ->select('customer_id')
            ->whereIn('customer_id', $customerIds === [] ? [''] : $customerIds)
            ->whereNotNull('customer_id');
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>|null  $locationIds
     */
    private function bookings(array $terms, ?array $locationIds, int $page): QuickSearchGroup
    {
        $ids = $this->ids(StaffSearchIndex::BOOKING, $terms, $locationIds);
        $page = $this->page($page);

        if ($ids === []) {
            return QuickSearchGroup::empty('bookings', 'Bookings');
        }

        $paginator = Booking::query()
            ->with(['customer', 'service', 'location'])
            ->whereIn('id', $ids)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'bookings_page', $page);

        $hits = [];

        foreach ($paginator as $booking) {
            $customer = $booking->customer;
            $name = $customer?->anonymized_at === null
                ? ($customer?->name ?: ($customer?->email ?: 'Walk-in'))
                : $booking->id;
            $zone = $booking->location?->timezone;
            $zone = is_string($zone) && $zone !== '' ? $zone : 'UTC';
            $when = Display::dateTime($booking->starts_at, $zone);
            $place = $booking->location?->name ?: 'Location';
            $service = $booking->service?->name ?: 'Booking';

            $hits[] = new QuickSearchHit(
                'booking',
                $booking->id,
                $name,
                $service.' · '.$place.' · '.$when.' · '.$booking->id,
                '/staff/bookings/'.$booking->id,
            );
        }

        return $this->group('bookings', 'Bookings', $hits, $paginator);
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>|null  $locationIds
     */
    private function customers(array $terms, ?array $locationIds, int $page): QuickSearchGroup
    {
        $ids = $this->ids(StaffSearchIndex::CUSTOMER, $terms, null);
        $page = $this->page($page);

        if ($ids !== [] && $locationIds !== null) {
            $ids = $this->bookingsForCustomers($ids)
                ->distinct()
                ->pluck('customer_id')
                ->all();
            $ids = array_values(array_filter($ids, is_string(...)));
        }

        if ($ids === []) {
            return QuickSearchGroup::empty('customers', 'Customers');
        }

        $paginator = Customer::query()
            ->whereNull('anonymized_at')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'customers_page', $page);

        $hits = [];

        foreach ($paginator as $customer) {
            $detail = array_values(array_filter([
                $customer->email,
                $customer->phone,
            ], fn (?string $value): bool => is_string($value) && $value !== ''));
            $needle = $customer->email ?: $customer->name;

            $hits[] = new QuickSearchHit(
                'customer',
                $customer->id,
                $customer->name,
                $detail === [] ? 'Customer' : implode(' · ', $detail),
                '/staff/bookings?search='.rawurlencode($needle),
            );
        }

        return $this->group('customers', 'Customers', $hits, $paginator);
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>|null  $locationIds
     */
    private function inventory(array $terms, ?array $locationIds, int $page): QuickSearchGroup
    {
        $page = $this->page($page);
        $assetIds = $this->ids(StaffSearchIndex::ASSET, $terms, $locationIds);
        $poolIds = $this->ids(StaffSearchIndex::POOL, $terms, $locationIds);
        $queries = [];

        if ($assetIds !== []) {
            $queries[] = Asset::query()
                ->whereIn('id', $assetIds)
                ->select(['id', 'name', 'location_id'])
                ->selectRaw("'asset' as item_kind");
        }

        if ($poolIds !== []) {
            $queries[] = Pool::query()
                ->whereIn('id', $poolIds)
                ->select(['id', 'name', 'location_id'])
                ->selectRaw("'pool' as item_kind");
        }

        if ($queries === []) {
            return QuickSearchGroup::empty('inventory', 'Inventory');
        }

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union = $union->unionAll($query);
        }

        $total = (int) DB::query()->fromSub(clone $union, 'inventory_matches')->count();
        $rows = DB::query()
            ->fromSub($union, 'inventory_matches')
            ->orderBy('name')
            ->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $locationIdsOnPage = [];

        foreach ($rows as $row) {
            if (isset($row->location_id) && is_string($row->location_id)) {
                $locationIdsOnPage[] = $row->location_id;
            }
        }

        $places = $locationIdsOnPage === []
            ? collect()
            : Location::query()->whereIn('id', $locationIdsOnPage)->pluck('name', 'id');

        $hits = [];

        foreach ($rows as $row) {
            $id = isset($row->id) && is_string($row->id) ? $row->id : '';
            $kind = isset($row->item_kind) && is_string($row->item_kind) ? $row->item_kind : '';

            if ($id === '' || ($kind !== 'asset' && $kind !== 'pool')) {
                continue;
            }

            $place = $places->get($row->location_id ?? '');
            $label = $kind === 'asset' ? 'Individual item' : 'Counted gear';

            $hits[] = new QuickSearchHit(
                'inventory',
                $id,
                isset($row->name) && is_string($row->name) ? $row->name : 'Item',
                $label.(is_string($place) && $place !== '' ? ' · '.$place : ''),
                '/staff/setup/resources#'.$kind.'-'.$id,
            );
        }

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return new QuickSearchGroup('inventory', 'Inventory', $hits, $page, $lastPage, $total);
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>|null  $locationIds
     * @return list<string>
     */
    private function ids(string $type, array $terms, ?array $locationIds): array
    {
        $ids = null;

        foreach ($terms as $term) {
            $query = $this->tokenLookup($type, $term, $locationIds);

            if ($ids !== null) {
                if ($ids === []) {
                    return [];
                }

                $query->whereIn('subject_id', $ids);
            }

            $ids = $query->distinct()->limit(self::ID_CAP)->pluck('subject_id')->all();
            $ids = array_values(array_filter($ids, is_string(...)));
        }

        return $ids ?? [];
    }

    private function prefix(string $term): string
    {
        return addcslashes($term, '%_\\').'%';
    }

    private function page(int $page): int
    {
        return max(1, min(50, $page));
    }

    /**
     * @param  list<QuickSearchHit>  $hits
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    private function group(string $type, string $label, array $hits, LengthAwarePaginator $paginator): QuickSearchGroup
    {
        return new QuickSearchGroup(
            $type,
            $label,
            $hits,
            $paginator->currentPage(),
            max(1, $paginator->lastPage()),
            $paginator->total(),
        );
    }
}
