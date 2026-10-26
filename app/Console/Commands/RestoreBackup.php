<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseRestorer;
use App\Services\Backup\RestoreReport;
use Illuminate\Console\Command;

class RestoreBackup extends Command
{
    protected $signature = 'reserveworx:restore-backup
        {path : Mode 600 gzip of one MySQL or MariaDB dump}
        {--force : Replay the dump into the configured database}';

    protected $description = 'Restore one database gzip into the configured database. This command does not print secrets.';

    public function handle(DatabaseRestorer $restorer): int
    {
        $connection = $this->connection();
        if ($connection === null) {
            $this->line('restore=failed settings');

            return self::FAILURE;
        }

        $report = $restorer->restore($this->argument('path'), $connection, (bool) $this->option('force'));
        $this->printReport($report);

        return $report->restored ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}|null
     */
    private function connection(): ?array
    {
        $mysql = config('database.connections.mysql');
        if (! is_array($mysql)) {
            return null;
        }

        $host = $mysql['host'] ?? null;
        $port = $mysql['port'] ?? null;
        $database = $mysql['database'] ?? null;
        $username = $mysql['username'] ?? null;
        $password = $mysql['password'] ?? '';

        if (! is_string($host) || ! is_string($database) || ! is_string($username) || ! is_string($password)) {
            return null;
        }

        if (is_int($port)) {
            $portNumber = $port;
        } elseif (is_string($port) && preg_match('/^[0-9]+$/', $port) === 1) {
            $portNumber = (int) $port;
        } else {
            return null;
        }

        return [
            'host' => $host,
            'port' => $portNumber,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }

    private function printReport(RestoreReport $report): void
    {
        $status = $report->status === 'ok' || $report->status === 'refused'
            ? $report->status
            : 'failed '.$report->status;
        $this->line('restore='.$status);

        if ($report->database !== '') {
            $this->line('database='.$report->database);
        }

        if ($report->restored) {
            $this->line('statements='.$report->statements);
        }
    }
}
