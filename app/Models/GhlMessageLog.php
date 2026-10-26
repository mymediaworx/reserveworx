<?php

namespace App\Models;

use App\Enums\GhlMessageKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GhlMessageLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GhlMessageLog extends Model
{
    /** @use HasFactory<GhlMessageLogFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'payment_id',
        'refund_id',
        'checkout_id',
        'kind',
        'channel',
        'dedupe_key',
        'status',
        'attempts',
        'http_status',
        'error',
        'location_id',
        'ghl_message_id',
        'offset_minutes',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'http_status' => 'integer',
        'offset_minutes' => 'integer',
    ];

    public function label(): string
    {
        $kind = GhlMessageKind::tryFrom($this->kind);

        return $kind instanceof GhlMessageKind ? $kind->label() : $this->kind;
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
