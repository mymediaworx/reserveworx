<?php

namespace App\Services\Catalog;

use App\Domain\Catalog\AddOnCharge;
use App\Enums\AddOnPricing;
use App\Models\AddOn;
use App\Models\Booking;
use App\Models\BookingAddOn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookingAddOnLines
{
    /**
     * Price the catalog rows the caller chose. Totals are fixed at this moment.
     *
     * @param  array<int|string, mixed>  $requested
     * @return list<array{add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>
     */
    public function price(int $participants, array $requested): array
    {
        if ($participants < 1) {
            throw ValidationException::withMessages([
                'participants' => 'A party needs at least one person.',
            ]);
        }

        /** @var list<array{id: string, quantity: int, index: int|string}> $choices */
        $choices = [];
        $seen = [];

        foreach ($requested as $index => $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                throw ValidationException::withMessages([
                    'add_ons.'.$index.'.id' => 'That add-on is not in this workspace.',
                ]);
            }

            $id = $row['id'];

            if (isset($seen[$id])) {
                throw ValidationException::withMessages([
                    'add_ons.'.$index.'.id' => 'Choose each add-on once.',
                ]);
            }

            $seen[$id] = true;
            $quantity = array_key_exists('quantity', $row) ? $row['quantity'] : 1;
            $choices[] = [
                'id' => $id,
                'quantity' => is_numeric($quantity) ? (int) $quantity : 0,
                'index' => $index,
            ];
        }

        $catalog = AddOn::query()->whereIn('id', array_column($choices, 'id'))->get()->keyBy('id');
        $lines = [];

        foreach ($choices as $choice) {
            $addOn = $catalog->get($choice['id']);
            $key = 'add_ons.'.$choice['index'];

            if (! $addOn instanceof AddOn) {
                throw ValidationException::withMessages([
                    $key.'.id' => 'That add-on is not in this workspace.',
                ]);
            }

            if (! $addOn->active) {
                throw ValidationException::withMessages([
                    $key.'.id' => 'That add-on is not available.',
                ]);
            }

            $quantity = $this->quantity($addOn, $choice['quantity'], $choice['index']);
            $total = AddOnCharge::total($addOn->pricing, $addOn->amount_cents, $quantity, $participants);

            if ($total > 2_000_000_000) {
                throw ValidationException::withMessages([
                    $key.'.quantity' => 'That add-on total is too large.',
                ]);
            }

            $lines[] = [
                'add_on_id' => $addOn->id,
                'name' => $addOn->name,
                'kind' => $addOn->kind->value,
                'pricing' => $addOn->pricing->value,
                'unit_amount_cents' => $addOn->amount_cents,
                'quantity' => $quantity,
                'total_cents' => $total,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>  $lines
     */
    public function replace(Booking $booking, array $lines): void
    {
        DB::transaction(function () use ($booking, $lines): void {
            BookingAddOn::query()->where('booking_id', $booking->id)->delete();

            foreach ($lines as $line) {
                BookingAddOn::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'add_on_id' => $line['add_on_id'],
                    'name' => $line['name'],
                    'kind' => $line['kind'],
                    'pricing' => $line['pricing'],
                    'unit_amount_cents' => $line['unit_amount_cents'],
                    'quantity' => $line['quantity'],
                    'total_cents' => $line['total_cents'],
                ]);
            }
        });
    }

    /**
     * @return list<array{id: string, add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>
     */
    public function snapshot(Booking $booking): array
    {
        $rows = [];

        foreach ($booking->addOns()->get() as $line) {
            $rows[] = $this->row($line);
        }

        return $rows;
    }

    /**
     * @return array{id: string, add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}
     */
    public function row(BookingAddOn $line): array
    {
        return [
            'id' => $line->id,
            'add_on_id' => (string) $line->add_on_id,
            'name' => $line->name,
            'kind' => $line->kind->value,
            'pricing' => $line->pricing->value,
            'unit_amount_cents' => $line->unit_amount_cents,
            'quantity' => $line->quantity,
            'total_cents' => $line->total_cents,
        ];
    }

    private function quantity(AddOn $addOn, int $quantity, int|string $index): int
    {
        if ($addOn->pricing === AddOnPricing::Each) {
            $max = $addOn->max_quantity ?? 100;

            if ($quantity < 1 || $quantity > $max) {
                throw ValidationException::withMessages([
                    'add_ons.'.$index.'.quantity' => 'Choose between 1 and '.$max.'.',
                ]);
            }

            return $quantity;
        }

        if ($quantity !== 1) {
            $message = $addOn->pricing === AddOnPricing::PerPerson
                ? 'A per-person add-on uses the party size.'
                : 'A flat add-on is charged once.';

            throw ValidationException::withMessages([
                'add_ons.'.$index.'.quantity' => $message,
            ]);
        }

        return 1;
    }
}
