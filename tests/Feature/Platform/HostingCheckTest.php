<?php

namespace Tests\Feature\Platform;

use App\Support\HostCrontab;
use App\Support\HostPlanName;
use App\Support\HostToolchain;
use App\Support\PhpHostFacts;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class HostingCheckTest extends TestCase
{
    use DatabaseMigrations;

    public function test_prints_host_facts_without_secrets_and_rolls_the_scratch_row_back(): void
    {
        config([
            'app.key' => 'base64:rw-hosting-app-key-canary',
            'database.connections.mysql.database' => 'rw-hosting-database-canary',
            'database.connections.mysql.username' => 'rw-hosting-user-canary',
            'database.connections.mysql.password' => 'rw-hosting-secret-canary',
            'mail.mailers.smtp.password' => 'rw-hosting-mail-canary',
            'queue.default' => 'database',
        ]);
        DB::table('cache')->insert([
            'key' => 'hosting-check-sentinel',
            'value' => 'rw-hosting-sentinel-secret',
            'expiration' => 1,
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $version = DB::selectOne('select version() as version');
        $this->assertInstanceOf(stdClass::class, $version);
        $this->assertStringContainsString('db_version='.$version->version, $output);
        $this->assertStringContainsString('table=cache engine=InnoDB', $output);
        $this->assertStringContainsString('innodb=yes', $output);
        $this->assertStringNotContainsString('non_innodb=', $output);
        $this->assertStringContainsString('for_update=ok', $output);
        $this->assertStringContainsString('transaction_rollback=ok', $output);
        $this->assertStringContainsString('php_version='.PHP_VERSION, $output);
        $this->assertStringContainsString('max_execution_time='.ini_get('max_execution_time'), $output);
        $this->assertStringContainsString('memory_limit='.ini_get('memory_limit'), $output);
        $this->assertStringContainsString('queue_connection=database', $output);
        $this->assertStringContainsString('queue_work_max_time=20', $output);
        $this->assertStringContainsString('schedule=* * * * * queue:work database --stop-when-empty --tries=3 --max-time=20 withoutOverlapping=yes', $output);
        $this->assertStringContainsString('schedule=* * * * * holds:purge-expired withoutOverlapping=yes', $output);
        $this->assertStringContainsString('schedule=* * * * * integrations:dispatch withoutOverlapping=yes', $output);
        $this->assertStringContainsString('schedule=* * * * * rentals:flag-overdue withoutOverlapping=yes', $output);
        $this->assertStringContainsString('schedule=0 * * * * reports:build-summaries withoutOverlapping=yes', $output);
        $this->assertStringContainsString('cron_command=schedule:run', $output);
        $this->assertMatchesRegularExpression('/^host_crontab=(read|empty|unavailable)$/m', $output);
        $this->assertStringNotContainsString('host_crontab=not read', $output);
        $this->assertPlanNameLine($output);
        $this->assertToolchainLines($output);
        $this->assertMatchesRegularExpression('/app_tables=(\d+)/', $output, $output);
        preg_match('/app_tables=(\d+)/', $output, $tables);
        preg_match('/app_tables_innodb=(\d+)/', $output, $innodbTables);
        $this->assertSame($tables[1], $innodbTables[1]);
        $this->assertGreaterThan(0, (int) $tables[1]);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringNotContainsString('rw-hosting-user-canary', $output);
        $this->assertStringNotContainsString('rw-hosting-database-canary', $output);
        $this->assertStringNotContainsString('rw-hosting-mail-canary', $output);
        $this->assertStringNotContainsString('rw-hosting-app-key-canary', $output);
        $this->assertStringNotContainsString('rw-hosting-sentinel-secret', $output);
        $this->assertStringNotContainsString('reserveworx', $output);
        $queries = array_map(
            static fn (array $query): string => strtolower((string) $query['query']),
            DB::getQueryLog(),
        );
        $this->assertTrue(
            collect($queries)->contains(fn (string $query): bool => str_contains($query, 'for update')),
            implode("\n", $queries),
        );
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('cache', [
            'key' => 'hosting-check-sentinel',
            'value' => 'rw-hosting-sentinel-secret',
        ]);
        $this->assertDatabaseMissing('cache', ['key' => 'reserveworx:hosting-check:probe']);
    }

    public function test_a_non_innodb_app_table_fails_the_check(): void
    {
        Schema::create('hosting_myisam_probe', function (Blueprint $table): void {
            $table->integer('id');
            $table->engine = 'MyISAM';
        });

        try {
            $exitCode = Artisan::call('reserveworx:hosting-check');
            $output = Artisan::output();
        } finally {
            Schema::dropIfExists('hosting_myisam_probe');
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('table=hosting_myisam_probe engine=MyISAM', $output);
        $this->assertStringContainsString('innodb=no', $output);
        $this->assertStringContainsString('non_innodb=hosting_myisam_probe=MyISAM', $output);
        $this->assertStringContainsString('plan_name=not_measured', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
    }

    public function test_the_plan_name_is_not_measured_and_host_text_is_not_copied(): void
    {
        $before = $this->moneyCounts();
        $this->app->instance(HostPlanName::class, new HostPlanName(
            session: fn (): string => implode("\n", [
                'GoGeek',
                'StartUp GrowBig Cloud',
                'plan_name=GoGeek',
                '/home/customer rw-hosting-secret-canary',
                'hostname=cloud.example',
            ]),
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertPlanNameLine($output);
        $this->assertStringNotContainsString('GoGeek', $output);
        $this->assertStringNotContainsString('StartUp', $output);
        $this->assertStringNotContainsString('GrowBig', $output);
        $this->assertStringNotContainsString('Cloud', $output);
        $this->assertStringNotContainsString('cloud.example', $output);
        $this->assertStringNotContainsString('/home/', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringNotContainsString('reserveworx', $output);
        $this->assertSame($before, $this->moneyCounts());
        $this->assertDatabaseMissing('cache', ['key' => 'reserveworx:hosting-check:probe']);

        $this->app->instance(HostPlanName::class, new HostPlanName(
            session: function (): string {
                throw new RuntimeException('GoGeek rw-hosting-secret-canary /home/customer');
            },
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertPlanNameLine($output);
        $this->assertStringNotContainsString('GoGeek', $output);
        $this->assertStringNotContainsString('RuntimeException', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringNotContainsString('/home/', $output);

        $this->app->instance(HostPlanName::class, new HostPlanName(
            session: fn (): false => false,
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertPlanNameLine($output);
        $this->assertSame($before, $this->moneyCounts());

        $script = (string) file_get_contents(base_path('scripts/hosting-check-remote.sh'));
        $this->assertStringContainsString('plan_name=not_measured', $script);
        $this->assertStringNotContainsString('plan_name=GoGeek', $script);
        $this->assertStringNotContainsString('plan_name=StartUp', $script);
        $this->assertStringNotContainsString('plan_name=GrowBig', $script);
        $this->assertStringNotContainsString('plan_name=Cloud', $script);
    }

    public function test_prints_php_binaries_and_extensions_without_failing_when_one_is_missing(): void
    {
        $this->app->instance(PhpHostFacts::class, new PhpHostFacts(
            probes: fn (): array => [
                ['path' => '/usr/bin/php', 'output' => 'PHP 8.2.35 (cli) (built: Jan 1 2026) Zend Engine v4.2.35'],
                ['path' => '/usr/local/php82', 'output' => 'password=rw-hosting-secret-canary'],
                ['path' => '/tmp/rw-hosting-secret-canary', 'output' => 'PHP 8.2.35'],
                ['path' => "/usr/bin/php\nrw-hosting-secret-canary", 'output' => 'PHP 8.2.35'],
                ['path' => '/usr/bin/php', 'output' => 'PHP 9.9.9'],
            ],
            extensions: fn (): array => ['bcmath', 'Ctype'],
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $running = strpos($output, 'php_binary=/usr/bin/php version=8.2.35');
        $unread = strpos($output, 'php_binary=/usr/local/php82 version=unread');
        $this->assertNotFalse($running);
        $this->assertNotFalse($unread);
        $this->assertLessThan($unread, $running);
        $this->assertSame(1, substr_count($output, 'php_binary=/usr/bin/php '));
        $this->assertStringNotContainsString('PHP 9.9.9', $output);
        $this->assertStringNotContainsString('Zend', $output);
        $this->assertStringNotContainsString('/tmp/', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringContainsString('php_extension=ctype=yes', $output);
        $this->assertStringContainsString('php_extension=bcmath=yes', $output);
        $this->assertStringContainsString('php_extension=gd=no', $output);
        $this->assertStringContainsString('php_extension=pdo_mysql=no', $output);
        $this->assertStringContainsString('php_extensions=missing', $output);
        $this->assertStringContainsString('php_version='.PHP_VERSION, $output);
        $this->assertStringContainsString('plan_name=not_measured', $output);
        $this->assertMatchesRegularExpression('/^host_crontab=(read|empty|unavailable)$/m', $output);
        $this->assertStringNotContainsString('host_crontab=not read', $output);
    }

    public function test_the_running_process_names_its_php_binary_and_loaded_extensions(): void
    {
        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('php_binary='.$this->runningBinary().' version='.PHP_VERSION, $output);
        $missing = false;

        foreach (PhpHostFacts::EXTENSIONS as $extension) {
            $present = extension_loaded($extension);
            $missing = $missing || ! $present;
            $this->assertStringContainsString(
                'php_extension='.$extension.'='.($present ? 'yes' : 'no'),
                $output,
            );
        }

        $this->assertStringContainsString('php_extensions='.($missing ? 'missing' : 'ok'), $output);
        $this->assertStringContainsString('plan_name=not_measured', $output);
        $this->assertMatchesRegularExpression('/^host_crontab=(read|empty|unavailable)$/m', $output);
        $this->assertStringNotContainsString('Zend', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
    }

    public function test_git_and_composer_versions_are_reported_without_command_text(): void
    {
        $this->app->instance(HostToolchain::class, new HostToolchain(
            probes: fn (): array => [
                [
                    'tool' => 'composer',
                    'output' => "Composer version 2.4.3 2022-11-30 16:22:29\n/home/customer/secret rw-hosting-secret-canary",
                    'ok' => true,
                ],
                [
                    'tool' => 'git',
                    'output' => "git version 9.9.9\nrw-hosting-secret-canary",
                    'ok' => false,
                ],
                [
                    'tool' => 'ssh',
                    'output' => 'OpenSSH_9.6 rw-hosting-secret-canary',
                    'ok' => true,
                ],
                [
                    'tool' => "git\nrw-hosting-secret-canary",
                    'output' => 'git version 1.2.3',
                    'ok' => true,
                ],
                [
                    'tool' => 'git',
                    'output' => "git version 2.56.0\n/usr/bin/git rw-hosting-secret-canary",
                    'ok' => true,
                ],
                [
                    'tool' => 'git',
                    'output' => 'git version 8.8.8',
                    'ok' => true,
                ],
            ],
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $git = strpos($output, "git=available\ngit_version=2.56.0");
        $composer = strpos($output, "composer=available\ncomposer_version=2.4.3");
        $this->assertNotFalse($git);
        $this->assertNotFalse($composer);
        $this->assertLessThan($composer, $git);
        $this->assertStringNotContainsString('git_version=9.9.9', $output);
        $this->assertStringNotContainsString('git_version=8.8.8', $output);
        $this->assertStringNotContainsString('git_version=1.2.3', $output);
        $this->assertStringNotContainsString('git version', $output);
        $this->assertStringNotContainsString('Composer version', $output);
        $this->assertStringNotContainsString('/home/', $output);
        $this->assertStringNotContainsString('/usr/bin/git', $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringNotContainsString('ssh=', $output);
        $this->assertStringNotContainsString('reserveworx', $output);
        $this->assertDatabaseMissing('cache', ['key' => 'reserveworx:hosting-check:probe']);
    }

    public function test_a_toolchain_that_cannot_run_is_unavailable_and_does_not_fail(): void
    {
        $this->app->instance(HostToolchain::class, new HostToolchain(
            probes: fn (): array => [
                ['tool' => 'git', 'output' => 'git version 2.56.0', 'ok' => false],
                ['tool' => 'composer', 'output' => 'Composer failed rw-hosting-secret-canary', 'ok' => true],
            ],
        ));

        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('git=unavailable', $output);
        $this->assertStringNotContainsString('git_version=', $output);
        $this->assertStringContainsString("composer=available\ncomposer_version=unread", $output);
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
        $this->assertStringNotContainsString('Composer failed', $output);
    }

    private function assertToolchainLines(string $output): void
    {
        foreach (['git', 'composer'] as $tool) {
            $this->assertMatchesRegularExpression('/^'.$tool.'=(available|unavailable)$/m', $output);

            if (preg_match('/^'.$tool.'=available$/m', $output) === 1) {
                $this->assertMatchesRegularExpression('/^'.$tool.'_version=(\d+\.\d+\.\d+|unread)$/m', $output);

                continue;
            }

            $this->assertStringNotContainsString($tool.'_version=', $output);
        }
    }

    public function test_a_schedule_run_crontab_reports_the_shortest_gap_and_hides_the_command(): void
    {
        $output = $this->checkWithCrontab(<<<'CRON'
            # reserveworx super-secret-token
            MAIL_PASSWORD=super-secret-token
            * * * * * cd /home/reserveworx && DB_PASSWORD=super-secret-token php artisan schedule:run
            0 * * * * echo super-secret-token
            */30 * * * * php /home/reserveworx/artisan schedule:run
            15 4 * * * php artisan schedule:run --secret=super-secret-token
            CRON);

        $this->assertStringContainsString('host_crontab=read', $output);
        $this->assertStringContainsString('host_crontab_schedule=yes', $output);
        $this->assertStringContainsString('host_crontab_expression=* * * * *', $output);
        $this->assertStringContainsString('host_crontab_expression=*/30 * * * *', $output);
        $this->assertStringContainsString('host_crontab_expression=15 4 * * *', $output);
        $this->assertStringContainsString('host_crontab_interval_minutes=1', $output);
        $this->assertStringContainsString('plan_name=not_measured', $output);
        $this->assertMatchesRegularExpression('/^php_binary=.+ version=/m', $output);
        $this->assertMatchesRegularExpression('/^php_extension=/m', $output);
        $this->assertMatchesRegularExpression('/^php_extensions=(ok|missing)$/m', $output);
        $this->assertStringNotContainsString('super-secret-token', $output);
        $this->assertStringNotContainsString('reserveworx', $output);
        $this->assertStringNotContainsString('MAIL_PASSWORD', $output);
        $this->assertStringNotContainsString('DB_PASSWORD', $output);
        $this->assertStringNotContainsString('/home/', $output);
    }

    public function test_pinned_and_hourly_schedules_are_sixty_minutes(): void
    {
        $hourly = $this->checkWithCrontab("0 * * * * php artisan schedule:run\n");
        $alias = $this->checkWithCrontab("@hourly php artisan schedule:run\n");
        $step = $this->checkWithCrontab("*/5 * * * * php artisan schedule:run\n");

        $this->assertStringContainsString('host_crontab_expression=0 * * * *', $hourly);
        $this->assertStringContainsString('host_crontab_interval_minutes=60', $hourly);
        $this->assertStringContainsString('host_crontab_expression=@hourly', $alias);
        $this->assertStringContainsString('host_crontab_interval_minutes=60', $alias);
        $this->assertStringContainsString('host_crontab_expression=*/5 * * * *', $step);
        $this->assertStringContainsString('host_crontab_interval_minutes=5', $step);
    }

    public function test_a_line_that_is_not_a_user_schedule_does_not_claim_an_interval(): void
    {
        $extra = $this->checkWithCrontab("* * * * * * php artisan schedule:run\n");
        $other = $this->checkWithCrontab("*/15 * * * * php artisan queue:work\n");
        $empty = $this->checkWithCrontab('');
        $missing = $this->checkWithCrontab(null);

        $this->assertStringContainsString('host_crontab_expression=unparsed', $extra);
        $this->assertStringContainsString('host_crontab_interval_minutes=unparsed', $extra);
        $this->assertStringContainsString('host_crontab=read', $other);
        $this->assertStringContainsString('host_crontab_schedule=no', $other);
        $this->assertStringNotContainsString('host_crontab_interval_minutes=', $other);
        $this->assertStringContainsString('host_crontab=empty', $empty);
        $this->assertStringNotContainsString('host_crontab_schedule=', $empty);
        $this->assertStringContainsString('host_crontab=unavailable', $missing);
        $this->assertStringNotContainsString('host_crontab_schedule=', $missing);
    }

    private function assertPlanNameLine(string $output): void
    {
        $scheduler = strpos($output, 'scheduler=');
        $plan = strpos($output, 'plan_name=not_measured');
        $this->assertNotFalse($scheduler);
        $this->assertNotFalse($plan);
        $this->assertLessThan($plan, $scheduler);
        $this->assertSame(1, substr_count($output, 'plan_name='));
        $this->assertDoesNotMatchRegularExpression('/^plan_name=(startup|growbig|gogeek|cloud)$/mi', $output);
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => DB::table('price_rules')->count(),
            'payments' => DB::table('payments')->count(),
            'fees' => DB::table('platform_fees')->count(),
            'fee_refunds' => DB::table('platform_fee_refunds')->count(),
            'refunds' => DB::table('refunds')->count(),
            'taxes' => DB::table('location_tax_rules')->count(),
        ];
    }

    private function checkWithCrontab(?string $text): string
    {
        $this->app->instance(HostCrontab::class, new HostCrontab(fn (): ?string => $text));
        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    private function runningBinary(): string
    {
        if (preg_match('#^/[A-Za-z0-9_./+-]+$#', PHP_BINARY) === 1 && ! str_contains(PHP_BINARY, '..')) {
            return PHP_BINARY;
        }

        return 'current';
    }
}
