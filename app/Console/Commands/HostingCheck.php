<?php

namespace App\Console\Commands;

use App\Support\HostPlanName;
use App\Support\SchedulerHeartbeat;
use App\Support\SharedHostBudget;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

class HostingCheck extends Command
{
    private const PROBE_KEY = 'reserveworx:hosting-check:probe';

    protected $signature = 'reserveworx:hosting-check';

    protected $description = 'Print Section 2.2 host facts. This command does not print secrets.';

    public function handle(Schedule $schedule, HostPlanName $planName): int
    {
        $databaseReady = $this->printDatabase();
        $this->printPhp();
        $this->printQueue($schedule);
        $this->printPlanName($planName);

        return $databaseReady ? self::SUCCESS : self::FAILURE;
    }

    private function printDatabase(): bool
    {
        $ready = $this->printServerVersion();
        $ready = $this->printStorageEngines() && $ready;
        $ready = $this->proveRowLock() && $ready;

        return $ready;
    }

    private function printServerVersion(): bool
    {
        try {
            $version = DB::selectOne('select version() as version');
            $value = $version instanceof stdClass ? $this->column($version, 'version') : '';
        } catch (Throwable) {
            $value = '';
        }

        if ($value === '') {
            $this->fact('db_version', 'unavailable');

            return false;
        }

        $this->fact('db_version', $value);

        return true;
    }

    private function printStorageEngines(): bool
    {
        try {
            /** @var list<stdClass> $rows */
            $rows = DB::select('show table status');
        } catch (Throwable) {
            $this->fact('app_tables', 'unavailable');
            $this->fact('innodb', 'unavailable');

            return false;
        }

        usort($rows, fn (stdClass $left, stdClass $right): int => strcmp($this->column($left, 'Name'), $this->column($right, 'Name')));

        $tables = 0;
        $innodb = 0;
        $others = [];

        foreach ($rows as $row) {
            if (strtoupper($this->column($row, 'Comment')) === 'VIEW') {
                continue;
            }

            $name = $this->column($row, 'Name');
            $engine = $this->column($row, 'Engine');
            if ($name === '') {
                continue;
            }

            $tables++;
            if (strcasecmp($engine, 'InnoDB') === 0) {
                $innodb++;
            } else {
                $others[] = $name.'='.($engine === '' ? 'none' : $engine);
            }

            $this->fact('table', $name.' engine='.($engine === '' ? 'none' : $engine));
        }

        $this->fact('app_tables', (string) $tables);
        $this->fact('app_tables_innodb', (string) $innodb);
        $this->fact('innodb', $tables > 0 && $others === [] ? 'yes' : 'no');

        foreach ($others as $other) {
            $this->fact('non_innodb', $other);
        }

        return $tables > 0 && $others === [];
    }

    private function proveRowLock(): bool
    {
        $level = DB::transactionLevel();

        try {
            $existed = DB::table('cache')->where('key', self::PROBE_KEY)->exists();
            DB::beginTransaction();
            DB::table('cache')->where('key', self::PROBE_KEY)->delete();
            DB::table('cache')->insert([
                'key' => self::PROBE_KEY,
                'value' => 'probe',
                'expiration' => 1,
            ]);
            $locked = DB::table('cache')->where('key', self::PROBE_KEY)->lockForUpdate()->first();
            $lockedKey = $locked instanceof stdClass && is_string($locked->key) ? $locked->key : '';
            $this->restoreTransactionLevel($level);
            $existsAfter = DB::table('cache')->where('key', self::PROBE_KEY)->exists();
            $lockedRow = $lockedKey === self::PROBE_KEY;
            $rolledBack = $existsAfter === $existed;
        } catch (Throwable) {
            $this->restoreTransactionLevel($level);
            $lockedRow = false;
            $rolledBack = false;
        }

        $this->fact('for_update', $lockedRow ? 'ok' : 'failed');
        $this->fact('transaction_rollback', $rolledBack ? 'ok' : 'failed');

        return $lockedRow && $rolledBack;
    }

    private function restoreTransactionLevel(int $level): void
    {
        while (DB::transactionLevel() > $level) {
            DB::rollBack();
        }
    }

    private function printPhp(): void
    {
        $this->fact('php_version', PHP_VERSION);
        $this->fact('max_execution_time', $this->iniValue('max_execution_time'));
        $this->fact('memory_limit', $this->iniValue('memory_limit'));
    }

    private function iniValue(string $name): string
    {
        $value = ini_get($name);

        return is_string($value) ? $value : '';
    }

    private function printQueue(Schedule $schedule): void
    {
        $connection = config('queue.default');
        $maxTime = config('reserveworx.queue_work_max_time');

        $this->fact('queue_connection', is_string($connection) ? $connection : '');
        $this->fact('queue_work_max_time', is_numeric($maxTime) ? (string) (int) $maxTime : '');
        $this->fact('shared_host_window', (string) SharedHostBudget::seconds());

        foreach ($schedule->events() as $event) {
            $command = $this->scheduledCommand($event);
            $overlap = $event->withoutOverlapping ? 'yes' : 'no';
            $this->fact('schedule', $event->expression.' '.$command.' withoutOverlapping='.$overlap);
        }

        $this->fact('cron_command', 'schedule:run');
        $this->fact('host_crontab', 'not read');
        $heartbeat = app(SchedulerHeartbeat::class);
        $this->fact('scheduler_heartbeat', $heartbeat->lastRun()?->format('Y-m-d\TH:i:s\Z') ?? 'not_seen');
        $this->fact('scheduler', $heartbeat->status());
    }

    private function printPlanName(HostPlanName $planName): void
    {
        foreach ($planName->facts() as [$key, $value]) {
            $this->fact($key, $value);
        }
    }

    private function scheduledCommand(Event $event): string
    {
        $command = $event->command;
        if (! is_string($command)) {
            return is_string($event->description) ? $event->description : '';
        }

        $normalized = str_replace(["'", '"'], '', Event::normalizeCommand($command));
        $marker = 'artisan ';
        $position = strpos($normalized, $marker);
        if ($position === false) {
            return trim($normalized);
        }

        return trim(substr($normalized, $position + strlen($marker)));
    }

    private function column(object $row, string $name): string
    {
        foreach (get_object_vars($row) as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value)) {
                return (string) $value;
            }

            return '';
        }

        return '';
    }

    private function fact(string $key, string $value): void
    {
        $this->line($key.'='.str_replace(["\r", "\n"], '', $value));
    }
}
