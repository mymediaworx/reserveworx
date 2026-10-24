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
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'service_id',
        'service_variant_id',
        'departure_id',
        'customer_id',
        'created_by',
        'guide_user_id',
        'attention_reason',
        'status',
        'payment_status',
        'operational_status',
        'checked_in_at',
        'checked_in_by',
        'check_in_waiver_override_reason',
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
        'checked_in_at' => 'datetime',
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
     * @return BelongsTo<ServiceVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
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
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
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
     * Named people on this booking. The relation is not called participants
     * because that attribute is the party-size integer.
     *
     * @return HasMany<Participant, $this>
     */
    public function namedParticipants(): HasMany
    {
        return $this->hasMany(Participant::class)->orderBy('id');
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
        return $this->hasMany(Participant::class)->orderBy('id');
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

    /**
     * Required operator fee lines copied onto this booking. Per-person and percent
     * totals are recalculated when the party size or service total is repriced.
     *
     * @return HasMany<BookingOperatorFee, $this>
     */
    public function operatorFees(): HasMany
    {
        return $this->hasMany(BookingOperatorFee::class)->orderBy('id');
    }

    /**
     * @return HasMany<BookingReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    /**
     * @return HasOne<FeedbackRequest, $this>
     */
    public function feedbackRequest(): HasOne
    {
        return $this->hasOne(FeedbackRequest::class);
    }
}
