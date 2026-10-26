<?php

namespace App\Models;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'asset_id',
        'pool_id',
        'severity',
        'summary',
        'notes',
        'damage_cents',
        'status',
        'reported_by',
        'resolved_at',
        'resolved_by',
        'maintenance_window_id',
        'occurred_at',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'severity' => IncidentSeverity::class,
        'damage_cents' => 'integer',
        'status' => IncidentStatus::class,
        'resolved_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<MaintenanceWindow, $this>
     */
    public function maintenanceWindow(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWindow::class);
    }

    /**
     * @return HasMany<IncidentPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(IncidentPhoto::class)->orderBy('id');
    }
}
