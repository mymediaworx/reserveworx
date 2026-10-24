<?php

namespace App\Services\Catalog;

use App\Domain\Catalog\OperatorFeeCharge;
use App\Domain\Pricing\PricingEngine;
use App\Enums\OperatorFeePricing;
use App\Enums\OperatorFeeScope;
use App\Models\Booking;
use App\Models\BookingOperatorFee;
use App\Models\OperatorFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Services\Allocation\AllocationUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BookingOperatorFeeLines
{
    public function __construct(private readonly PricingEngine $pricing) {}

    /**
     * Active fees for this service and this location. A percent line is included
     * even when the service total is not known yet. The caller prices it.
     *
     * @return list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>
     */
    public function definitionsFor(?string $serviceId, ?string $locationId): array
    {
        if ($serviceId === null || $serviceId === '') {
            return [];
        }

        $fees = OperatorFee::query()
            ->where('active', true)
            ->where(function ($query) use ($serviceId, $locationId): void {
                $query->where('service_id', $serviceId);

                if ($locationId !== null && $locationId !== '') {
                    $query->orWhere('location_id', $locationId);
                }
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $definitions = [];

        foreach ($fees as $fee) {
            $definitions[] = $this->definitionFromCatalog($fee);
        }

        return $definitions;
    }

    /**
     * Copy the active fees onto the hold. A percent fee waits until a service
     * total exists, which the first quote supplies.
     */
    public function attach(Booking $booking): void
    {
        $definitions = $this->definitionsFor($booking->service_id, $booking->location_id);
        $serviceCents = $this->serviceCents($booking);

        if ($serviceCents === null) {
            $priced = [];

            foreach ($definitions as $definition) {
                if ($definition['pricing'] === OperatorFeePricing::Percent->value) {
                    continue;
                }

                $priced[] = $definition;
            }

            $definitions = $priced;
            $serviceCents = 0;
        }

        try {
            $lines = OperatorFeeCharge::lines($definitions, $booking->participants, $serviceCents);
        } catch (InvalidArgumentException $exception) {
            throw new AllocationUnavailableException($exception->getMessage());
        }

        $this->replace($booking, $lines);
    }

    /**
     * Retotal the lines already stored for this booking after the party size changes.
     *
     * Before a snapshot exists, the live catalog applies again. After a snapshot,
     * per-person and percent lines use the copied rate and the new party, and a
     * catalog edit does not get in. Flat lines stay at the copied amount.
     */
    public function repriceForParty(Booking $booking): void
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if ($snapshot instanceof PricingSnapshot) {
            $payload = $snapshot->payload;
            $stored = $payload['operator_fees'] ?? null;

            if (! is_array($stored)) {
                return;
            }

            $serviceCents = $this->serviceCents($booking);

            if ($serviceCents === null) {
                return;
            }

            try {
                $lines = OperatorFeeCharge::lines($this->definitionsFromLines($stored), $booking->participants, $serviceCents);
            } catch (InvalidArgumentException $exception) {
                throw new AllocationUnavailableException($exception->getMessage());
            }

            $this->replace($booking, $lines);

            return;
        }

        $this->attach($booking);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>
     */
    public function definitionsFromLines(array $lines): array
    {
        $definitions = [];

        foreach ($lines as $line) {
            $normalized = $this->normalize($line);
            $definitions[] = [
                'operator_fee_id' => $normalized['operator_fee_id'],
                'name' => $normalized['name'],
                'pricing' => $normalized['pricing'],
                'scope' => $normalized['scope'],
                'amount_cents' => $normalized['amount_cents'],
                'percent_bps' => $normalized['percent_bps'],
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<array{id: string, operator_fee_id: string, name: string, scope: string, pricing: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>
     */
    public function replace(Booking $booking, array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $normalized[] = $this->normalize($line);
        }

        return DB::transaction(function () use ($booking, $normalized): array {
            BookingOperatorFee::query()->where('booking_id', $booking->id)->delete();
            $stored = [];

            foreach ($normalized as $line) {
                $row = BookingOperatorFee::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'operator_fee_id' => $line['operator_fee_id'],
                    'name' => $line['name'],
                    'scope' => $line['scope'],
                    'pricing' => $line['pricing'],
                    'amount_cents' => $line['amount_cents'],
                    'percent_bps' => $line['percent_bps'],
                    'quantity' => $line['quantity'],
                    'total_cents' => $line['total_cents'],
                ]);
                $stored[] = $this->row($row);
            }

            return $stored;
        });
    }

    /**
     * @return array{id: string, operator_fee_id: string, name: string, scope: string, pricing: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}
     */
    public function row(BookingOperatorFee $line): array
    {
        return [
            'id' => $line->id,
            'operator_fee_id' => $line->operator_fee_id,
            'name' => $line->name,
            'scope' => $line->scope->value,
            'pricing' => $line->pricing->value,
            'amount_cents' => $line->amount_cents,
            'percent_bps' => $line->percent_bps,
            'quantity' => $line->quantity,
            'total_cents' => $line->total_cents,
        ];
    }

    /**
     * @return array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}
     */
    private function definitionFromCatalog(OperatorFee $fee): array
    {
        $scope = $fee->service_id !== null ? OperatorFeeScope::Service : OperatorFeeScope::Location;

        return [
            'operator_fee_id' => $fee->id,
            'name' => $fee->name,
            'pricing' => $fee->pricing->value,
            'scope' => $scope->value,
            'amount_cents' => $fee->amount_cents,
            'percent_bps' => $fee->percent_bps,
        ];
    }

    private function serviceCents(Booking $booking): ?int
    {
        $booking->loadMissing('location');
        $rules = PriceRule::inputsFor($booking->service_id);

        if ($rules === []) {
            return null;
        }

        $seconds = CarbonImmutable::instance($booking->ends_at)->utc()->getTimestamp()
            - CarbonImmutable::instance($booking->starts_at)->utc()->getTimestamp();
        $duration = intdiv($seconds, 60);

        if ($duration < 1 || $booking->participants < 1) {
            return null;
        }

        $zone = $booking->location !== null ? $booking->location->timezone : 'UTC';
        $on = CarbonImmutable::instance($booking->starts_at)->timezone($zone);

        try {
            return $this->pricing->serviceCents($rules, $booking->participants, $duration, $on);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}
     */
    private function normalize(mixed $line): array
    {
        if (! is_array($line)) {
            throw new InvalidArgumentException('A mandatory fee line is invalid.');
        }

        $id = $line['operator_fee_id'] ?? null;
        $name = $line['name'] ?? null;
        $pricing = $line['pricing'] ?? null;
        $scope = $line['scope'] ?? null;
        $quantity = $line['quantity'] ?? null;
        $total = $line['total_cents'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($name) || $name === '' || ! is_string($pricing) || ! is_string($scope)) {
            throw new InvalidArgumentException('A mandatory fee line is invalid.');
        }

        if (! is_numeric($quantity) || (int) $quantity < 1 || ! is_numeric($total) || (int) $total < 0) {
            throw new InvalidArgumentException('A mandatory fee line is invalid.');
        }

        $amount = $line['amount_cents'] ?? null;
        $percent = $line['percent_bps'] ?? null;

        return [
            'operator_fee_id' => $id,
            'name' => $name,
            'pricing' => $pricing,
            'scope' => $scope,
            'amount_cents' => is_numeric($amount) ? (int) $amount : null,
            'percent_bps' => is_numeric($percent) ? (int) $percent : null,
            'quantity' => (int) $quantity,
            'total_cents' => (int) $total,
        ];
    }
}
