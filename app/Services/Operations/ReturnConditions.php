<?php

namespace App\Services\Operations;

use App\Enums\ResourceStatus;
use App\Enums\ReturnCondition;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Stores the condition of each asset on a return and sends damaged gear
 * through the same maintenance flag used when an asset status changes.
 */
final class ReturnConditions
{
    public function __construct(private readonly AffectedBookings $affected) {}

    /**
     * @param  Collection<int, RentalCheckout>  $openCheckouts
     * @param  list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>|null  $conditions
     * @return array{conditions: list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>, maintained_asset_ids: list<string>}
     */
    public function record(Booking $booking, Collection $openCheckouts, ?array $conditions): array
    {
        if ($conditions === null) {
            return ['conditions' => [], 'maintained_asset_ids' => []];
        }

        /** @var array<string, RentalCheckout> $assetCheckouts */
        $assetCheckouts = [];

        foreach ($openCheckouts as $checkout) {
            $assetId = $checkout->asset_id;

            if (! is_string($assetId) || $assetId === '') {
                continue;
            }

            $assetCheckouts[$assetId] = $checkout;
        }

        $expected = array_keys($assetCheckouts);
        $given = array_map(fn (array $row): string => $row['asset_id'], $conditions);
        sort($expected);
        sort($given);

        if ($expected !== $given) {
            throw ValidationException::withMessages([
                'conditions' => 'Record a condition for every asset on this rental.',
            ]);
        }

        $stored = [];
        $maintained = [];

        foreach ($conditions as $condition) {
            $checkout = $assetCheckouts[$condition['asset_id']];
            $asset = Asset::query()->find($condition['asset_id']);

            if (! $asset instanceof Asset) {
                throw ValidationException::withMessages([
                    'conditions' => 'Record a condition for every asset on this rental.',
                ]);
            }

            $status = ReturnCondition::from($condition['condition']);
            $notes = $condition['notes'];
            RentalReturn::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'rental_checkout_id' => $checkout->id,
                'asset_id' => $asset->id,
                'condition' => $status,
                'notes' => $notes,
                'recorded_by' => $condition['recorded_by'],
            ]);

            if ($status === ReturnCondition::Damaged) {
                $previous = $asset->status;

                if ($previous !== ResourceStatus::Maintenance) {
                    $asset->status = ResourceStatus::Maintenance;
                    $asset->save();
                    $this->affected->flagAssetStatus($asset, $previous);
                    $maintained[] = $asset->id;
                }
            }

            $stored[] = [
                'asset_id' => $asset->id,
                'condition' => $status->value,
                'notes' => $notes,
                'recorded_by' => $condition['recorded_by'],
            ];
        }

        return ['conditions' => $stored, 'maintained_asset_ids' => $maintained];
    }
}
