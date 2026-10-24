<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformFeeRefund extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * Rule recorded on a fee refund a platform administrator issued outside Section 7.7.
     */
    public const RULE = 'platform_manual';

    protected $fillable = [
        'tenant_id',
        'platform_fee_id',
        'amount_cents',
        'reason',
        'rule',
        'stripe_fee_refund_id',
        'idempotency_key',
        'actor_user_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<PlatformFee, $this>
     */
    public function platformFee(): BelongsTo
    {
        return $this->belongsTo(PlatformFee::class);
    }
}
