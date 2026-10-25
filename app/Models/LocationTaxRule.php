<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LocationTaxRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationTaxRule extends Model
{
    /** @use HasFactory<LocationTaxRuleFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'name',
        'percent_bps',
        'applies_to_rentals',
        'applies_to_add_ons',
        'applies_to_fees',
    ];

    protected $casts = [
        'percent_bps' => 'integer',
        'applies_to_rentals' => 'boolean',
        'applies_to_add_ons' => 'boolean',
        'applies_to_fees' => 'boolean',
    ];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function percentLabel(): string
    {
        $whole = intdiv($this->percent_bps, 100);
        $fraction = $this->percent_bps % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    public function appliesLabel(): string
    {
        $parts = [];

        if ($this->applies_to_rentals) {
            $parts[] = 'Rentals';
        }

        if ($this->applies_to_add_ons) {
            $parts[] = 'Add-ons';
        }

        if ($this->applies_to_fees) {
            $parts[] = 'Fees';
        }

        return implode(', ', $parts);
    }

    /**
     * Frozen rule stored on a pricing snapshot.
     *
     * @return array{id: string, name: string, percent_bps: int, applies_to_rentals: bool, applies_to_add_ons: bool, applies_to_fees: bool}
     */
    public function toPricingRule(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'percent_bps' => $this->percent_bps,
            'applies_to_rentals' => $this->applies_to_rentals,
            'applies_to_add_ons' => $this->applies_to_add_ons,
            'applies_to_fees' => $this->applies_to_fees,
        ];
    }
}
