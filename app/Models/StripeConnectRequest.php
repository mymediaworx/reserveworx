<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered Stripe Connect link request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $idempotency_key
 * @property string $payload_hash
 */
class StripeConnectRequest extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'account_id',
        'idempotency_key',
        'payload_hash',
    ];

    /**
     * @return BelongsTo<StripeAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StripeAccount::class, 'account_id');
    }
}
