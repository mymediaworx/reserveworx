<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'service_id',
        'departure_id',
        'customer_id',
        'created_by',
        'guide_user_id',
        'attention_reason',
        'status',
        'payment_status',
        'operational_status',
        'starts_at',
        'ends_at',
        'participants',
        'idempotency_key',
        'payload_hash',
    ];

    protected $casts = [
        'status' => BookingStatus::class,
        'payment_status' => PaymentStatus::class,
        'operational_status' => OperationalStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'participants' => 'integer',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Hold, $this>
     */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    /**
     * @return HasMany<Allocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /**
     * @return HasMany<BookingLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(BookingLineItem::class);
    }

    /**
     * @return HasMany<RentalCheckout, $this>
     */
    public function checkouts(): HasMany
    {
        return $this->hasMany(RentalCheckout::class);
    }

    /**
     * Named people on this booking. This is not the party-size column.
     *
     * @return HasMany<Participant, $this>
     */
    public function partyMembers(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * Add-on and equipment lines chosen for this booking. Totals are fixed when chosen.
     *
     * @return HasMany<BookingAddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(BookingAddOn::class)->orderBy('id');
    }
}
