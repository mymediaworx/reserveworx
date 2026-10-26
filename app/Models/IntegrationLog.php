<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IntegrationLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One outbound integration attempt for a workspace (spec Section 13).
 *
 * Rows are append-only. A later try is a new row, so the diagnostics page
 * can show the backoff history the outbox row overwrites.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $kind
 * @property int $attempt
 * @property string $status
 * @property int|null $http_status
 * @property string|null $error
 * @property Carbon|null $available_at
 * @property Carbon $created_at
 */
class IntegrationLog extends Model
{
    /** @use HasFactory<IntegrationLogFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    public const CHANNEL_OUTBOX = 'outbox';

    public const CHANNEL_WEBHOOK = 'webhook';

    public const CHANNEL_GHL_SYNC = 'ghl_sync';

    public const CHANNEL_GHL_MESSAGE = 'ghl_message';

    protected $fillable = [
        'tenant_id',
        'channel',
        'kind',
        'attempt',
        'status',
        'http_status',
        'error',
        'available_at',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'http_status' => 'integer',
        'available_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Integration logs are a record of an attempt and cannot be changed.');
        });

        static::deleting(function (): void {
            throw new LogicException('Integration logs are a record of an attempt and cannot be deleted.');
        });
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_OUTBOX => 'Outbox',
            self::CHANNEL_WEBHOOK => 'Webhook',
            self::CHANNEL_GHL_SYNC => 'GoHighLevel contact',
            self::CHANNEL_GHL_MESSAGE => 'GoHighLevel message',
            default => $this->channel,
        };
    }
}
