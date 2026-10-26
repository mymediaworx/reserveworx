<?php

namespace App\Services\Operations;

use App\Enums\ResourceStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\Pool;
use App\Models\RentalCheckout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Boats and gear types a damage report can name for one booking.
 *
 * An asset already on an allocation, hold, or checkout comes first. A pool
 * adds the active assets in that pool's category at the pool's location.
 * A pool with no such assets, including a count-only pool, is reported as
 * the gear type itself.
 */
final class IncidentAssets
{
    public const POOL_PREFIX = 'pool:';

    /**
     * @return array{assets: Collection<int, Asset>, pools: Collection<int, Pool>}
     */
    public function options(Booking $booking): array
    {
        $rows = $this->rows($booking);
        $specificIds = [];
        $poolIds = [];

        foreach ($rows as $row) {
            $assetId = $this->present($row->getAttribute('asset_id'));

            if ($assetId !== null) {
                $specificIds[$assetId] = $assetId;
            }

            $poolId = $this->present($row->getAttribute('pool_id'));

            if ($poolId !== null) {
                $poolIds[$poolId] = $poolId;
            }
        }

        $specific = $specificIds === []
            ? new Collection
            : Asset::query()->whereIn('id', array_values($specificIds))->orderBy('name')->orderBy('id')->get();

        if ($poolIds === []) {
            return [
                'assets' => $specific,
                'pools' => new Collection,
            ];
        }

        $pools = Pool::query()->whereIn('id', array_values($poolIds))->get();
        $matchedIds = [];
        $uncoveredIds = [];

        foreach ($pools as $pool) {
            $matched = $this->activeAssets($pool);

            if ($matched->isEmpty()) {
                $uncoveredIds[$pool->id] = $pool->id;

                continue;
            }

            foreach ($matched as $asset) {
                if (isset($specificIds[$asset->id])) {
                    continue;
                }

                $matchedIds[$asset->id] = $asset->id;
            }
        }

        $matchedAssets = $matchedIds === []
            ? new Collection
            : Asset::query()->whereIn('id', array_values($matchedIds))->orderBy('name')->orderBy('id')->get();

        $assets = new Collection;

        foreach ($specific as $asset) {
            $assets->push($asset);
        }

        foreach ($matchedAssets as $asset) {
            $assets->push($asset);
        }

        $uncovered = $uncoveredIds === []
            ? new Collection
            : Pool::query()->whereIn('id', array_values($uncoveredIds))->orderBy('name')->orderBy('id')->get();

        return [
            'assets' => $assets,
            'pools' => $uncovered,
        ];
    }

    /**
     * @return Collection<int, Asset>
     */
    public function forBooking(Booking $booking): Collection
    {
        return $this->options($booking)['assets'];
    }

    /**
     * Pools on the booking that have no active individual assets to list.
     *
     * @return Collection<int, Pool>
     */
    public function poolsForBooking(Booking $booking): Collection
    {
        return $this->options($booking)['pools'];
    }

    public function bookingHasResource(Booking $booking): bool
    {
        foreach ($this->rows($booking) as $row) {
            if ($this->present($row->getAttribute('asset_id')) !== null || $this->present($row->getAttribute('pool_id')) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Active boats of this pool's category at the pool's own location.
     * A count-only pool, or a pool with no category, has none.
     *
     * @return Collection<int, Asset>
     */
    private function activeAssets(Pool $pool): Collection
    {
        $categoryId = $this->present($pool->resource_category_id);
        $locationId = $this->present($pool->location_id);

        if ($categoryId === null || $locationId === null) {
            return new Collection;
        }

        return Asset::query()
            ->where('location_id', $locationId)
            ->where('resource_category_id', $categoryId)
            ->where('status', ResourceStatus::Active)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return BaseCollection<int, Allocation|Hold|RentalCheckout>
     */
    private function rows(Booking $booking): BaseCollection
    {
        $columns = ['asset_id', 'pool_id'];

        return $booking->allocations()->get($columns)
            ->concat($booking->holds()->get($columns))
            ->concat($booking->checkouts()->get($columns))
            ->values();
    }

    private function present(mixed $id): ?string
    {
        return is_string($id) && $id !== '' ? $id : null;
    }
}
