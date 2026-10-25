<?php

namespace App\Models;

use App\Enums\StaffUndoKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StaffUndoActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffUndoAction extends Model
{
    /** @use HasFactory<StaffUndoActionFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'location_id',
        'kind',
        'subject_type',
        'subject_id',
        'payload',
        'message',
        'expires_at',
        'undone_at',
        'committed_at',
    ];

    protected $casts = [
        'kind' => StaffUndoKind::class,
        'payload' => 'array',
        'expires_at' => 'datetime',
        'undone_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isOpen(): bool
    {
        return $this->undone_at === null
            && $this->committed_at === null
            && $this->expires_at->greaterThan(now());
    }
}
