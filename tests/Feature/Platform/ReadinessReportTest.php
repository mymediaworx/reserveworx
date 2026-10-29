<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Readiness;
use App\Models\AuditLog;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\User;
use App\Support\DeployBackupPresence;
use App\Support\SchedulerHeartbeat;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReadinessReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const KEY_CANARY = 'CanaryKeyCanaryKeyCanaryKeyCanar';

    private const NAME_CANARY = 'canary-secret-name';

    private const PATH_CANARY = 'rw-backup-path-canary';

    /** @var list<string> */
    private array $scratchFiles = [];

    /** @var list<string> */
    private array $scratchDirectories = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $property = new ReflectionProperty(UserFactory::class, 'password');
        $property->setValue(null, null);

        foreach ($this->scratchFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->scratchDirectories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        parent::tearDown();
    }

    public function test_a_ready_server_passes_without_showing_the_key_or_writing_money(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        $this->lockEncryption();
        $admin = $this->admin();
        config([
            'app.key' => self::KEY_CANARY,
            'hashing.driver' => 'argon2id',
        ]);
        app(SchedulerHeartbeat::class)->beat();
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('password_hash=pass')
            ->expectsOutputToContain('php_mail=pass')
            ->expectsOutputToContain('app_key=pass')
            ->expectsOutputToContain('cipher=pass')
            ->expectsOutputToContain('rate_limits=pass')
            ->expectsOutputToContain('queue=pass')
            ->expectsOutputToContain('debug=pass')
            ->expectsOutputToContain('restore_command=pass')
            ->expectsOutputToContain('deploy_backup=note')
            ->expectsOutputToContain('migrations=pass')
            ->expectsOutputToContain('session_secure=note')
            ->expectsOutputToContain('https=note')
            ->expectsOutputToContain('scheduler=pass')
            ->expectsOutputToContain('ready=yes')
            ->doesntExpectOutputToContain(self::KEY_CANARY)
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Production readiness')
            ->assertSee('Ready')
            ->assertSee('data-ready="yes"', false)
            ->assertSee('Passwords use Argon2id.')
            ->assertSee('The mailer is array.')
            ->assertSee('An application key is set.')
            ->assertSee('Encryption uses AES-256-CBC.')
            ->assertSee('Login, public signup, availability, and checkout are rate limited.')
            ->assertSee('The queue uses the database driver and the jobs table is present.')
            ->assertSee('Debug is off.')
            ->assertSee('reserveworx:restore-backup is registered.')
            ->assertSee('A private deploy backup is checked when the app is in production.')
            ->assertSee('Migrations are current.')
            ->assertSee('Secure cookies are checked when the app is in production.')
            ->assertSee('HTTPS is checked when the app is in production.')
            ->assertSee('The scheduler has run in the last 10 minutes.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('/platform/readiness', false)
            ->assertDontSee(self::KEY_CANARY);

        Livewire::actingAs($admin)->test(Readiness::class)
            ->assertSee('Passwords use Argon2id.')
            ->assertDontSee(self::KEY_CANARY);

        $this->assertUnchanged($audits);
    }

    public function test_php_mail_a_missing_scheduler_and_unsafe_names_are_not_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        $this->lockEncryption();
        $admin = $this->admin();
        config([
            'app.key' => self::KEY_CANARY,
            'app.debug' => true,
            'mail.default' => 'mail',
            'hashing.driver' => self::NAME_CANARY,
            'queue.default' => self::NAME_CANARY,
            'app.cipher' => self::NAME_CANARY,
        ]);
        Cache::forever('reserveworx:schedule:last-run', '2026-10-27T15:49:00Z');
        $this->forgetLimiter('checkout');
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('password_hash=fail')
            ->expectsOutputToContain('php_mail=fail')
            ->expectsOutputToContain('cipher=fail')
            ->expectsOutputToContain('rate_limits=fail')
            ->expectsOutputToContain('queue=fail')
            ->expectsOutputToContain('debug=fail')
            ->expectsOutputToContain('scheduler=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain(self::KEY_CANARY)
            ->doesntExpectOutputToContain(self::NAME_CANARY)
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Not ready')
            ->assertSee('data-ready="no"', false)
            ->assertSee('The password hasher is not bcrypt or Argon2id.')
            ->assertSee('The mailer is PHP mail().')
            ->assertSee('Encryption is not AES-256-CBC or AES-256-GCM.')
            ->assertSee('Missing checkout.')
            ->assertSee('The queue does not use the database driver.')
            ->assertSee('Debug is on.')
            ->assertSee('The scheduler last ran more than 10 minutes ago.')
            ->assertDontSee(self::KEY_CANARY)
            ->assertDontSee(self::NAME_CANARY);

        $this->assertUnchanged($audits);
    }

    public function test_a_missing_key_sync_queue_or_unrecorded_scheduler_is_not_ready(): void
    {
        $this->readyConfig();
        $this->lockEncryption();
        config([
            'app.key' => '',
            'queue.default' => 'sync',
        ]);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('app_key=fail')
            ->expectsOutputToContain('queue=fail')
            ->expectsOutputToContain('scheduler=fail')
            ->expectsOutputToContain('ready=no')
            ->assertFailed();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('An application key is missing.')
            ->assertSee('The queue uses sync.')
            ->assertSee('The scheduler has not been recorded.');
    }

    public function test_production_requires_https_and_a_secure_session_cookie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        $this->lockEncryption();
        app(SchedulerHeartbeat::class)->beat();
        $this->bindPresentDeployBackup();
        config([
            'app.env' => 'production',
            'app.url' => 'http://app.reserveworx.com',
            'session.secure' => false,
        ]);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('session_secure=fail')
            ->expectsOutputToContain('https=fail')
            ->expectsOutputToContain('deploy_backup=pass')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain('app.reserveworx.com')
            ->assertFailed();

        config([
            'app.url' => 'https://app.reserveworx.com',
            'session.secure' => 'true',
        ]);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('session_secure=pass')
            ->expectsOutputToContain('https=pass')
            ->expectsOutputToContain('deploy_backup=pass')
            ->expectsOutputToContain('ready=yes')
            ->doesntExpectOutputToContain('app.reserveworx.com')
            ->assertSuccessful();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('The session cookie is marked secure.')
            ->assertSee('The application URL uses HTTPS.')
            ->assertSee('A private deploy backup is present.')
            ->assertDontSee('https://app.reserveworx.com');
    }

    public function test_a_private_deploy_backup_is_required_in_production_and_the_path_stays_hidden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        $this->lockEncryption();
        app(SchedulerHeartbeat::class)->beat();
        $admin = $this->admin();
        $audits = AuditLog::query()->count();
        $directory = $this->scratchDirectory(self::PATH_CANARY);
        config([
            'app.env' => 'production',
            'app.url' => 'https://app.reserveworx.com',
            'session.secure' => true,
        ]);
        $this->bindDeployBackupDirectory($directory);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain(self::PATH_CANARY)
            ->doesntExpectOutputToContain('shared/backups')
            ->doesntExpectOutputToContain('.sql.gz')
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Not ready')
            ->assertSee('data-check="deploy_backup"', false)
            ->assertSee('data-state="fail"', false)
            ->assertSee('A private deploy backup was not found.')
            ->assertDontSee(self::PATH_CANARY)
            ->assertDontSee('shared/backups')
            ->assertDontSee('.sql.gz');

        $open = $this->scratchGzip($directory, '20261027T160000Z.sql.gz', 0644);
        $this->assertNotSame(0600, fileperms($open) & 0777);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain(self::PATH_CANARY)
            ->doesntExpectOutputToContain('20261027T160000Z')
            ->assertFailed();

        unlink($open);
        $this->scratchFiles = array_values(array_filter(
            $this->scratchFiles,
            fn (string $path): bool => $path !== $open,
        ));

        $plain = $directory.'/20261027T160100Z.sql.gz';
        file_put_contents($plain, 'not-a-gzip');
        chmod($plain, 0600);
        $this->scratchFiles[] = $plain;

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain('not-a-gzip')
            ->assertFailed();

        unlink($plain);
        $this->scratchFiles = array_values(array_filter(
            $this->scratchFiles,
            fn (string $path): bool => $path !== $plain,
        ));

        $this->scratchGzip($directory, '20261027T160200Z.sql.gz', 0600);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=pass')
            ->expectsOutputToContain('ready=yes')
            ->doesntExpectOutputToContain(self::PATH_CANARY)
            ->doesntExpectOutputToContain('20261027T160200Z')
            ->doesntExpectOutputToContain('-- MySQL dump')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Ready')
            ->assertSee('data-check="deploy_backup"', false)
            ->assertSee('data-state="pass"', false)
            ->assertSee('A private deploy backup is present.')
            ->assertDontSee(self::PATH_CANARY)
            ->assertDontSee('20261027T160200Z')
            ->assertDontSee('-- MySQL dump');

        $this->bindDeployBackupDirectory(fn () => throw new \RuntimeException(self::PATH_CANARY.' boom'));

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain(self::PATH_CANARY)
            ->doesntExpectOutputToContain('boom')
            ->assertFailed();

        $forbidden = $this->scratchDirectory('public_html');
        $this->scratchGzip($forbidden, '20261027T160300Z.sql.gz', 0600);
        $this->bindDeployBackupDirectory($forbidden);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('deploy_backup=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain('public_html')
            ->assertFailed();

        $this->assertUnchanged($audits);
    }

    public function test_a_migration_that_has_not_run_is_not_ready_and_nothing_is_written(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        $this->lockEncryption();
        app(SchedulerHeartbeat::class)->beat();
        $admin = $this->admin();
        $names = DB::table('migrations')->orderByDesc('id')->limit(2)->pluck('migration')->values();
        $this->assertCount(2, $names);
        $first = $names[0];
        $second = $names[1];
        $this->assertIsString($first);
        $this->assertIsString($second);
        DB::table('migrations')->where('migration', $first)->delete();
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('migrations=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain($first)
            ->doesntExpectOutputToContain('database/migrations')
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Not ready')
            ->assertSee('data-check="migrations"', false)
            ->assertSee('data-state="fail"', false)
            ->assertSee('1 migration has not run.')
            ->assertDontSee('Migrations are current.')
            ->assertDontSee($first)
            ->assertDontSee('database/migrations');

        $this->assertSame(0, DB::table('migrations')->where('migration', $first)->count());
        $this->assertUnchanged($audits);

        DB::table('migrations')->where('migration', $second)->delete();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('migrations=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain($second)
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('2 migrations have not run.')
            ->assertDontSee('1 migration has not run.')
            ->assertDontSee($second);

        $this->assertSame(0, DB::table('migrations')->whereIn('migration', [$first, $second])->count());
        $this->assertUnchanged($audits);
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_report(): void
    {
        $this->get('http://app.reserveworx.com/platform/readiness')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Readiness::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/readiness'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/readiness'))
            ->assertNotFound();
    }

    public function test_a_known_hasher_that_is_not_allowed_names_itself(): void
    {
        $this->readyConfig();
        $admin = $this->admin();
        config(['hashing.driver' => 'argon']);

        Artisan::call('reserveworx:readiness');

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Passwords use argon, which is not bcrypt or Argon2id.');
    }

    private function readyConfig(): void
    {
        config([
            'app.debug' => false,
            'app.env' => 'testing',
            'app.cipher' => 'AES-256-CBC',
            'hashing.driver' => 'bcrypt',
            'mail.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    private function lockEncryption(): void
    {
        app('encrypter');
    }

    private function forgetLimiter(string $name): void
    {
        $limiter = app(CacheRateLimiter::class);
        $property = new ReflectionProperty($limiter, 'limiters');
        $stored = $property->getValue($limiter);
        $this->assertIsArray($stored);
        unset($stored[$name]);
        $property->setValue($limiter, $stored);
    }

    private function assertUnchanged(int $audits): void
    {
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, PlatformFeeRefund::query()->count());
        $this->assertSame(0, PricingSnapshot::query()->count());
        $this->assertSame(0, PriceRule::query()->count());
        $this->assertSame(0, LocationTaxRule::query()->count());
    }

    private function bindPresentDeployBackup(): void
    {
        $directory = $this->scratchDirectory('present-backups');
        $this->scratchGzip($directory, '20261027T160000Z.sql.gz', 0600);
        $this->bindDeployBackupDirectory($directory);
    }

    private function bindDeployBackupDirectory(string|\Closure $directory): void
    {
        $resolver = $directory instanceof \Closure
            ? $directory
            : static fn (): string => $directory;

        app()->instance(DeployBackupPresence::class, new DeployBackupPresence($resolver));
    }

    private function scratchDirectory(string $suffix): string
    {
        $directory = sys_get_temp_dir().'/rw-readiness-'.$suffix.'-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700, true);
        $this->scratchDirectories[] = $directory;

        return $directory;
    }

    private function scratchGzip(string $directory, string $name, int $mode): string
    {
        $path = $directory.'/'.$name;
        $payload = gzencode("-- MySQL dump 10.13\nUSE `reserveworx_test`;\n");
        $this->assertIsString($payload);
        file_put_contents($path, $payload);
        chmod($path, $mode);
        $this->scratchFiles[] = $path;

        return $path;
    }
}
