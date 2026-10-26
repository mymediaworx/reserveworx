<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One successful retry record for a booking change.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $booking_id
 * @property string $action
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int|null $http_status
 * @property array<string, mixed>|null $response
 */
class BookingMutation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'action',
        'idempotency_key',
        'payload_hash',
        'http_status',
        'response',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'response' => 'array',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
