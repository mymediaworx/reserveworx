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
 * Boats a damage report can name for one booking.
 *
 * An asset already on an allocation, hold, or checkout comes first. A pool
 * row that does not name an asset adds the active assets in that pool's
 * category and location.
 */
final class IncidentAssets
{
    /**
     * @return Collection<int, Asset>
     */
    public function forBooking(Booking $booking): Collection
    {
        $rows = $this->rows($booking);
        $specificIds = [];

        foreach ($rows as $row) {
            $assetId = $this->present($row->getAttribute('asset_id'));

            if ($assetId !== null) {
                $specificIds[$assetId] = $assetId;
            }
        }

        $specific = $specificIds === []
            ? new Collection
            : Asset::query()->whereIn('id', array_values($specificIds))->orderBy('name')->orderBy('id')->get();

        $poolIds = [];

        foreach ($rows as $row) {
            if ($this->present($row->getAttribute('asset_id')) !== null) {
                continue;
            }

            $poolId = $this->present($row->getAttribute('pool_id'));

            if ($poolId !== null) {
                $poolIds[$poolId] = $poolId;
            }
        }

        if ($poolIds === []) {
            return $specific;
        }

        $categories = Pool::query()
            ->whereIn('id', array_values($poolIds))
            ->where('location_id', $booking->location_id)
            ->whereNotNull('resource_category_id')
            ->pluck('resource_category_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        if ($categories->isEmpty()) {
            return $specific;
        }

        $poolAssets = Asset::query()
            ->where('location_id', $booking->location_id)
            ->where('status', ResourceStatus::Active)
            ->whereIn('resource_category_id', $categories->all())
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $assets = new Collection;

        foreach ($specific as $asset) {
            $assets->push($asset);
        }

        foreach ($poolAssets as $asset) {
            if ($assets->contains('id', $asset->id)) {
                continue;
            }

            $assets->push($asset);
        }

        return $assets;
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
