<?php

namespace App\Console\Commands;

use App\Models\PlatformAdministrator;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'reserveworx:grant-platform-admin {email}';

    protected $description = 'Grant platform administrator access to an existing user';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('No user exists with that email. This command does not create users.');

            return self::FAILURE;
        }

        if ($user->isPlatformAdmin()) {
            $this->info('That user is already a platform administrator.');

            return self::SUCCESS;
        }

        PlatformAdministrator::query()->create(['user_id' => $user->id]);

        $audit->log(
            action: 'platform.admin_granted',
            subject: $user,
            properties: ['email' => $user->email],
            useContextTenant: false,
        );

        $this->info('Platform administrator access granted.');

        return self::SUCCESS;
    }
}
