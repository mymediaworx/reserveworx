<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookDelivery extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'webhook_endpoint_id',
        'event',
        'dedupe_key',
        'payload',
        'status',
        'attempt_count',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt_count' => 'integer',
    ];

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * @return HasMany<WebhookDeliveryAttempt, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class)->orderBy('attempt');
    }
}
