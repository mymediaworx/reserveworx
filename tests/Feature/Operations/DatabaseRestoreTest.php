<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseRestoreTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        Schema::dropIfExists('backup_restore_drills');
        Schema::dropIfExists('backup_restore_keepers');

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_a_gzip_dump_restores_the_saved_row_and_leaves_another_table(): void
    {
        $this->drillRow('changed', 'extra');
        $this->keeperRow();
        $database = $this->databaseName();
        $path = $this->gzip($this->dump($database, <<<'SQL'
DROP TABLE IF EXISTS `backup_restore_drills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_restore_drills` (
  `id` int NOT NULL,
  `label` varchar(32) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
LOCK TABLES `backup_restore_drills` WRITE;
/*!40000 ALTER TABLE `backup_restore_drills` DISABLE KEYS */;
INSERT INTO `backup_restore_drills` VALUES (1,'ke;pt');
/*!40000 ALTER TABLE `backup_restore_drills` ENABLE KEYS */;
UNLOCK TABLES;
SQL));

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
            '--force' => true,
        ])->expectsOutput('restore=ok')
            ->expectsOutput('database='.$database)
            ->expectsOutput('statements=30')
            ->doesntExpectOutputToContain('password')
            ->assertSuccessful();

        DB::reconnect();
        $this->assertSame('ke;pt', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
        $this->assertNull(DB::table('backup_restore_drills')->where('id', 2)->value('id'));
        $this->assertSame('stay', DB::table('backup_restore_keepers')->where('id', 1)->value('label'));
    }

    public function test_restore_without_force_leaves_the_row(): void
    {
        $this->drillRow('changed');
        $path = $this->gzip($this->dump($this->databaseName(), "UPDATE `backup_restore_drills` SET `label` = 'applied' WHERE `id` = 1;\n"));

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
        ])->expectsOutput('restore=refused')
            ->assertFailed();

        $this->assertSame('changed', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
    }

    public function test_a_dump_for_another_database_is_refused_and_does_not_create_it(): void
    {
        $this->drillRow('changed');
        $path = $this->gzip(<<<'SQL'
-- MySQL dump 10.13
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `other_workspace_db`;
USE `other_workspace_db`;
UPDATE `backup_restore_drills` SET `label` = 'applied' WHERE `id` = 1;
SQL);

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
            '--force' => true,
        ])->expectsOutput('restore=failed database')
            ->assertFailed();

        $this->assertSame('changed', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
        $this->assertSame(0, $this->schemaCount('other_workspace_db'));
    }

    public function test_a_dump_that_switches_database_midway_does_not_change_the_row(): void
    {
        $this->drillRow('changed');
        $database = $this->databaseName();
        $path = $this->gzip(<<<SQL
-- MySQL dump 10.13
CREATE DATABASE `{$database}`;
USE `{$database}`;
UPDATE `backup_restore_drills` SET `label` = 'applied' WHERE `id` = 1;
USE `other_workspace_db`;
SQL);

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
            '--force' => true,
        ])->expectsOutput('restore=failed database')
            ->assertFailed();

        $this->assertSame('changed', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
        $this->assertSame(0, $this->schemaCount('other_workspace_db'));
    }

    public function test_a_sql_error_stops_before_later_statements_and_the_output_hides_the_statement(): void
    {
        $this->drillRow('changed');
        $database = $this->databaseName();
        $path = $this->gzip(<<<SQL
-- MySQL dump 10.13
CREATE DATABASE `{$database}`;
USE `{$database}`;
UPDATE `backup_restore_drills` SET `missing_column` = 1 WHERE `id` = 1;
UPDATE `backup_restore_drills` SET `label` = 'applied' WHERE `id` = 1;
SQL);

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
            '--force' => true,
        ])->expectsOutput('restore=failed sql')
            ->doesntExpectOutputToContain('missing_column')
            ->assertFailed();

        $this->assertSame('changed', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
    }

    public function test_a_wrong_password_is_not_printed(): void
    {
        $this->drillRow('changed');
        config(['database.connections.mysql.password' => 'canary-secret-not-printed']);
        $path = $this->gzip($this->dump($this->databaseName(), "UPDATE `backup_restore_drills` SET `label` = 'applied' WHERE `id` = 1;\n"));

        $this->artisan('reserveworx:restore-backup', [
            'path' => $path,
            '--force' => true,
        ])->expectsOutput('restore=failed connection')
            ->doesntExpectOutputToContain('canary-secret-not-printed')
            ->assertFailed();

        config(['database.connections.mysql.password' => 'reserveworx']);
        DB::purge('mysql');
        $this->assertSame('changed', DB::table('backup_restore_drills')->where('id', 1)->value('label'));
    }

    private function drillRow(string $label, ?string $extra = null): void
    {
        Schema::dropIfExists('backup_restore_drills');
        DB::statement('CREATE TABLE backup_restore_drills (id int NOT NULL PRIMARY KEY, label varchar(32) NOT NULL)');
        DB::table('backup_restore_drills')->insert(['id' => 1, 'label' => $label]);
        if ($extra !== null) {
            DB::table('backup_restore_drills')->insert(['id' => 2, 'label' => $extra]);
        }
    }

    private function keeperRow(): void
    {
        Schema::dropIfExists('backup_restore_keepers');
        DB::statement('CREATE TABLE backup_restore_keepers (id int NOT NULL PRIMARY KEY, label varchar(32) NOT NULL)');
        DB::table('backup_restore_keepers')->insert(['id' => 1, 'label' => 'stay']);
    }

    private function dump(string $database, string $body): string
    {
        return <<<SQL
-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: {$database}
-- ------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `{$database}` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `{$database}`;

{$body}
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-26 14:35:29
SQL;
    }

    private function gzip(string $sql): string
    {
        $path = sys_get_temp_dir().'/rw-restore-'.bin2hex(random_bytes(4)).'.sql.gz';
        file_put_contents($path, gzencode($sql));
        chmod($path, 0600);
        $this->files[] = $path;

        return $path;
    }

    private function databaseName(): string
    {
        $database = config('database.connections.mysql.database');
        $this->assertIsString($database);

        return $database;
    }

    private function schemaCount(string $name): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name],
        );

        return (int) ($row->c ?? 0);
    }
}
