<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FeedbackRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackRequest extends Model
{
    /** @use HasFactory<FeedbackRequestFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'customer_id',
        'send_at',
        'sent_at',
        'skipped_at',
        'skip_reason',
        'rating',
        'comment',
        'rated_at',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
        'skipped_at' => 'datetime',
        'rated_at' => 'datetime',
        'rating' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
