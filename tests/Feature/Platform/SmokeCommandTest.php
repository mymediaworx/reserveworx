<?php

namespace Tests\Feature\Platform;

use App\Console\Commands\Smoke;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL_PASSWORD = 'rw-smoke-mail-password-canary';

    private const DB_PASSWORD = 'rw-smoke-db-password-canary';

    private const APP_KEY = 'base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_a_ready_release_prints_key_value_lines_and_no_secrets(): void
    {
        $this->prepareRelease();
        Cache::put(Smoke::SCHEDULE_LAST_RUN_KEY, '2026-10-24T17:00:00Z');

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('db=ok', $output);
        $this->assertStringContainsString('migrations=current', $output);
        $this->assertStringContainsString('migrations_pending=0', $output);
        $this->assertStringContainsString('roles=synced', $output);
        $this->assertStringContainsString('mail_configured=yes', $output);
        $this->assertStringContainsString('mail_mailer=smtp', $output);
        $this->assertStringContainsString('queue_connection=database', $output);
        $this->assertStringContainsString('queue_table=present', $output);
        $this->assertStringContainsString('demo_tenant=harbor-demo', $output);
        $this->assertStringContainsString('demo_tenant_resolved=yes', $output);
        $this->assertStringContainsString('demo_host=harbor-demo.reserveworx.test', $output);
        $this->assertStringContainsString('booking_http=200', $output);
        $this->assertStringContainsString('booking_page=ok', $output);
        $this->assertStringContainsString('staff_login_http=200', $output);
        $this->assertStringContainsString('staff_login_page=ok', $output);
        $this->assertStringContainsString('signup_http=200', $output);
        $this->assertStringContainsString('signup_page=ok', $output);
        $this->assertStringContainsString('scheduler_last_run=2026-10-24T17:00:00Z', $output);
        $this->assertStringContainsString('smoke=ok', $output);
        $this->assertStringNotContainsString('http://', $output);
        $this->assertStringNotContainsString('https://', $output);
        $this->assertStringNotContainsString('hosted_', $output);
        $this->assertSecretsStayOut($output);
        Http::assertNothingSent();
    }

    public function test_a_missing_scheduler_timestamp_is_reported_and_does_not_fail(): void
    {
        $this->prepareRelease();
        Cache::put(Smoke::SCHEDULE_LAST_RUN_KEY, 'rw-smoke-schedule-secret');

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('scheduler_last_run=not_recorded', $output);
        $this->assertStringContainsString('smoke=ok', $output);
        $this->assertStringNotContainsString('rw-smoke-schedule-secret', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_pending_migrations_fail_the_check(): void
    {
        $this->prepareRelease();
        $path = database_path('migrations/2099_01_01_000000_smoke_pending_probe.php');
        file_put_contents($path, "<?php\n");

        try {
            $exitCode = Artisan::call('reserveworx:smoke');
            $output = Artisan::output();
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('migrations=pending', $output);
        $this->assertStringContainsString('migrations_pending=1', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_roles_that_do_not_match_the_catalog_fail_the_check(): void
    {
        $this->prepareRelease();
        $owner = Role::query()->where('slug', 'owner')->firstOrFail();
        $permission = Permission::query()->where('slug', 'settings.view')->firstOrFail();
        $owner->permissions()->detach($permission->id);

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('roles=out_of_sync', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_mail_that_cannot_leave_the_server_fails_the_check(): void
    {
        $this->prepareRelease();
        config(['mail.default' => 'log']);

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('mail_configured=no', $output);
        $this->assertStringContainsString('mail_mailer=log', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_a_missing_queue_table_fails_the_check(): void
    {
        $this->prepareRelease();
        config(['queue.connections.database.table' => 'jobs_smoke_absent']);

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('queue_connection=database', $output);
        $this->assertStringContainsString('queue_table=missing', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_a_missing_demo_tenant_fails_without_requesting_its_pages(): void
    {
        $this->prepareRelease(withDemo: false);

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('demo_tenant=harbor-demo', $output);
        $this->assertStringContainsString('demo_tenant_resolved=no', $output);
        $this->assertStringContainsString('demo_host=none', $output);
        $this->assertStringContainsString('booking_http=skipped', $output);
        $this->assertStringContainsString('staff_login_http=skipped', $output);
        $this->assertStringContainsString('signup_http=200', $output);
        $this->assertStringContainsString('signup_page=ok', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    public function test_an_unreachable_database_fails_without_printing_the_password(): void
    {
        config([
            'database.connections.smoke_down' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 'reserveworx_missing',
                'username' => 'reserveworx',
                'password' => self::DB_PASSWORD,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'database.default' => 'smoke_down',
            'mail.default' => 'log',
            'app.key' => self::APP_KEY,
        ]);
        DB::purge('smoke_down');

        try {
            $exitCode = Artisan::call('reserveworx:smoke');
            $output = Artisan::output();
        } finally {
            config(['database.default' => 'mysql']);
            DB::purge('smoke_down');
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('db=unreachable', $output);
        $this->assertStringContainsString('migrations=unchecked', $output);
        $this->assertStringContainsString('roles=unchecked', $output);
        $this->assertStringContainsString('queue_table=unchecked', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertStringNotContainsString(self::DB_PASSWORD, $output);
        $this->assertStringNotContainsString(self::APP_KEY, $output);
    }

    public function test_a_suspended_demo_workspace_does_not_count_as_its_booking_page(): void
    {
        $this->prepareRelease(status: TenantStatus::Suspended);

        $exitCode = Artisan::call('reserveworx:smoke');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('demo_tenant_resolved=yes', $output);
        $this->assertStringContainsString('booking_http=403', $output);
        $this->assertStringContainsString('booking_page=failed', $output);
        $this->assertStringContainsString('smoke=failed', $output);
        $this->assertSecretsStayOut($output);
    }

    private function prepareRelease(bool $withDemo = true, TenantStatus $status = TenantStatus::Active): void
    {
        config([
            'app.key' => self::APP_KEY,
            'database.connections.mysql.password' => self::DB_PASSWORD,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mail.reserveworx.com',
            'mail.mailers.smtp.port' => 465,
            'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'noreply@reserveworx.com',
            'mail.mailers.smtp.password' => self::MAIL_PASSWORD,
            'mail.from.address' => 'noreply@reserveworx.com',
            'mail.from.name' => 'ReserveWorx',
            'queue.default' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $this->seed(RoleAndPermissionSeeder::class);

        if (! $withDemo) {
            return;
        }

        $tenant = Tenant::factory()->create([
            'name' => 'Harbor Demo',
            'slug' => 'harbor-demo',
            'status' => $status,
        ]);
        Domain::factory()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'harbor-demo.reserveworx.test',
            'type' => 'subdomain',
            'is_primary' => true,
            'verified_at' => now(),
        ]);
    }

    private function assertSecretsStayOut(string $output): void
    {
        $this->assertStringNotContainsString(self::MAIL_PASSWORD, $output);
        $this->assertStringNotContainsString(self::DB_PASSWORD, $output);
        $this->assertStringNotContainsString(self::APP_KEY, $output);
    }
}
