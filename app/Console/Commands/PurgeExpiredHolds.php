<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Tenancy\TenantScope;
use Illuminate\Console\Command;

class PurgeExpiredHolds extends Command
{
    protected $signature = 'holds:purge-expired';

    protected $description = 'Delete holds whose expiry has passed. Availability already ignores them.';

    public function handle(): int
    {
        $deleted = Hold::withoutGlobalScope(TenantScope::class)
            ->where('expires_at', '<=', now())
            ->delete();

        $this->line('holds_purged='.$deleted);

        return self::SUCCESS;
    }
}
