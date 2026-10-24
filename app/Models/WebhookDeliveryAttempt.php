<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDeliveryAttempt extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    public const BODY_LIMIT = 2000;

    protected $fillable = [
        'tenant_id',
        'webhook_delivery_id',
        'attempt',
        'status',
        'response_code',
        'duration_ms',
        'response_body',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'response_code' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
