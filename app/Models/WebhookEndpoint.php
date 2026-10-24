<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'url',
        'secret',
        'events',
        'active',
    ];

    protected $hidden = [
        'secret',
        'url_hash',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (WebhookEndpoint $endpoint): void {
            if ($endpoint->isDirty('url') && $endpoint->url !== '') {
                $endpoint->url_hash = hash('sha256', $endpoint->url);
            }
        });
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
