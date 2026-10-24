<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingReminderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingReminder extends Model
{
    /** @use HasFactory<BookingReminderFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'offset_minutes',
        'dispatched_at',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'dispatched_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
