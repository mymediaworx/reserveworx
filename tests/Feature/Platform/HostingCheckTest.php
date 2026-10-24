<?php

namespace Tests\Feature\Platform;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assertStringContainsString('host_crontab=not read', $output);
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
        $this->assertStringNotContainsString('rw-hosting-secret-canary', $output);
    }
}
