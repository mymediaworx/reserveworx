<?php

namespace App\Console\Commands;

use App\Models\PlatformAdministrator;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'reserveworx:grant-platform-admin {email} {--password-file=}';

    protected $description = 'Grant platform administrator access to an existing user';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('email is not valid.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $created = $this->createUser($email);

            if (! $created instanceof User) {
                return self::FAILURE;
            }

            $user = $created;
            $this->info('user_created=1');
        }

        if ($user->isPlatformAdmin()) {
            $this->info('That user is already a platform administrator.');
            $this->info('platform_admin=granted email='.$user->email);

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
        $this->info('platform_admin=granted email='.$user->email);

        return self::SUCCESS;
    }

    private function createUser(string $email): ?User
    {
        $path = $this->option('password-file');

        if (! is_string($path) || $path === '') {
            $this->error('No user exists with that email. This command does not create users.');

            return null;
        }

        if (str_contains($path, 'public_html') || str_contains($path, 'www/reserveworx.com')) {
            $this->error('password file path was rejected.');

            return null;
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error('password file directory is not writable.');

            return null;
        }

        $password = Str::password(24);
        $user = User::query()->create([
            'name' => 'Michael',
            'email' => $email,
            'password' => $password,
        ]);
        $user->email_verified_at = now();
        $user->save();

        $written = file_put_contents($path, $email.' '.$password.PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            $user->delete();
            $this->error('password file was not written.');

            return null;
        }

        chmod($path, 0600);

        return $user;
    }
}
