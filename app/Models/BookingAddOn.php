<?php

namespace App\Models;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingAddOnFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingAddOn extends Model
{
    /** @use HasFactory<BookingAddOnFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'add_on_id',
        'name',
        'kind',
        'pricing',
        'unit_amount_cents',
        'quantity',
        'total_cents',
    ];

    protected $casts = [
        'kind' => AddOnKind::class,
        'pricing' => AddOnPricing::class,
        'unit_amount_cents' => 'integer',
        'quantity' => 'integer',
        'total_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<AddOn, $this>
     */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
