<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\DatabaseRestorer;
use PHPUnit\Framework\TestCase;

class DatabaseRestorerTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }

        foreach ($this->directories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_a_dump_inside_a_public_web_directory_is_refused(): void
    {
        $root = $this->directory('public_html');
        $target = $root.'/backup.sql.gz';
        $this->writeGzip($target, "-- MySQL dump 10.13\nCREATE DATABASE `reserveworx_test`;\n");
        $linkDirectory = sys_get_temp_dir().'/rw-restore-link-'.bin2hex(random_bytes(4));
        mkdir($linkDirectory, 0700, true);
        $link = $linkDirectory.'/backup.sql.gz';
        symlink($target, $link);
        $this->files[] = $link;
        $this->directories[] = $linkDirectory;

        $report = (new DatabaseRestorer)->restore($link, $this->closedConnection(), true);

        $this->assertFalse($report->restored);
        $this->assertSame('path', $report->status);
    }

    public function test_a_dump_under_the_marketing_site_is_refused(): void
    {
        $root = $this->directory('www/reserveworx.com');
        $path = $root.'/backup.sql.gz';
        $this->writeGzip($path, "-- MySQL dump 10.13\nUSE `reserveworx_test`;\n");

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertFalse($report->restored);
        $this->assertSame('path', $report->status);
    }

    public function test_a_dump_that_is_not_private_is_refused(): void
    {
        $path = $this->gzip("-- MySQL dump 10.13\nUSE `reserveworx_test`;\n", 0644);

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('mode', $report->status);
        $this->assertSame(0, $report->statements);
    }

    public function test_a_file_that_is_not_gzip_is_refused(): void
    {
        $path = $this->path();
        file_put_contents($path, 'this is not a gzip');
        chmod($path, 0600);

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('archive', $report->status);
    }

    public function test_a_truncated_gzip_is_refused(): void
    {
        $path = $this->path();
        $encoded = gzencode("-- MySQL dump 10.13\nUSE `reserveworx_test`;\n");
        $this->assertIsString($encoded);
        file_put_contents($path, substr($encoded, 0, 10));
        chmod($path, 0600);
        $this->files[] = $path;

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('archive', $report->status);
    }

    public function test_a_gzip_that_is_not_a_database_dump_is_refused(): void
    {
        $path = $this->gzip("hello\n");

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('unrecognized', $report->status);
    }

    public function test_a_dump_with_a_routine_delimiter_is_refused(): void
    {
        $path = $this->gzip("-- MySQL dump 10.13\nDELIMITER $$\nCREATE DATABASE `reserveworx_test`;\n");

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('unrecognized', $report->status);
    }

    public function test_a_dump_that_names_no_database_is_refused(): void
    {
        $sql = <<<'SQL'
-- MySQL dump 10.13
CREATE TABLE `backup_restore_drills` (
  `id` int NOT NULL
);
SQL;
        $path = $this->gzip($sql);

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), true);

        $this->assertSame('database', $report->status);
        $this->assertSame('reserveworx_test', $report->database);
    }

    public function test_a_valid_dump_is_refused_until_force_is_set(): void
    {
        $path = $this->gzip("-- MySQL dump 10.13\nCREATE DATABASE `reserveworx_test`;\nUSE `reserveworx_test`;\n");

        $report = (new DatabaseRestorer)->restore($path, $this->closedConnection(), false);

        $this->assertFalse($report->restored);
        $this->assertSame('refused', $report->status);
        $this->assertSame(0, $report->statements);
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    private function closedConnection(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'reserveworx_test',
            'username' => 'reserveworx',
            'password' => 'not-used',
        ];
    }

    private function gzip(string $sql, int $mode = 0600): string
    {
        $path = $this->path();
        $this->writeGzip($path, $sql, $mode);

        return $path;
    }

    private function writeGzip(string $path, string $sql, int $mode = 0600): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, gzencode($sql));
        chmod($path, $mode);
        $this->files[] = $path;
    }

    private function path(): string
    {
        return sys_get_temp_dir().'/rw-restore-'.bin2hex(random_bytes(4)).'.sql.gz';
    }

    private function directory(string $suffix): string
    {
        $base = sys_get_temp_dir().'/rw-restore-dir-'.bin2hex(random_bytes(4));
        $root = $base.'/'.$suffix;
        mkdir($root, 0700, true);
        $this->directories[] = $base;

        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }
            unlink($path);
        }

        rmdir($directory);
    }
}
