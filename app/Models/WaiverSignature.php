<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverSignature extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'booking_id', 'participant_id', 'waiver_version_id', 'participant_name', 'is_minor',
        'guardian_name', 'ip_address', 'user_agent', 'content_hash', 'signed_at',
    ];

    protected $casts = [
        'is_minor' => 'boolean',
        'signed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WaiverVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WaiverVersion::class, 'waiver_version_id');
    }
}
