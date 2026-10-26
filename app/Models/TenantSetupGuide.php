<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's setup guide choices for one workspace: whether it is hidden, and
 * which steps were marked done or skipped by hand. Holds no money values.
 */
class TenantSetupGuide extends Model
{
    use BelongsToTenant, HasUlids;

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    protected $fillable = ['tenant_id', 'hidden', 'step_states'];

    protected $casts = [
        'hidden' => 'boolean',
        'step_states' => 'array',
    ];
}
