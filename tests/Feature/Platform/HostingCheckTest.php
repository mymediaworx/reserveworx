<?php

namespace Tests\Feature\Platform;

use App\Support\HostCrontab;
use App\Support\HostPlanName;
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

    private function checkWithCrontab(?string $text): string
    {
        $this->app->instance(HostCrontab::class, new HostCrontab(fn (): ?string => $text));
        $exitCode = Artisan::call('reserveworx:hosting-check');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }
}
