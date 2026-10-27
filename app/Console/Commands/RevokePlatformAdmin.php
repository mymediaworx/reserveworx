<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformAdminRevokeException;
use App\Services\Platform\PlatformAdminRevoker;
use Illuminate\Console\Command;

class RevokePlatformAdmin extends Command
{
    protected $signature = 'reserveworx:revoke-platform-admin {email}';

    protected $description = 'Remove platform administrator access. The user account stays';

    public function handle(PlatformAdminRevoker $revoker): int
    {
        try {
            $user = $revoker->revoke((string) $this->argument('email'), null);
        } catch (PlatformAdminRevokeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Platform administrator access removed.');
        $this->info('platform_admin=revoked email='.$user->email);

        return self::SUCCESS;
    }
}
