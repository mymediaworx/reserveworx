<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RowLockTest extends TestCase
{
    use DatabaseMigrations;

    public function test_select_for_update_blocks_a_second_connection(): void
    {
        $id = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $id,
            'name' => 'Lock Probe',
            'slug' => 'lock-probe',
            'status' => 'onboarding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'database.connections.mysql_lock' => config('database.connections.mysql'),
        ]);
        DB::purge('mysql_lock');

        $other = DB::connection('mysql_lock');

        DB::beginTransaction();
        DB::select('select id from tenants where id = ? for update', [$id]);

        $other->statement('set innodb_lock_wait_timeout = 1');
        $other->beginTransaction();

        try {
            $other->select('select id from tenants where id = ? for update', [$id]);
            $this->fail('The second connection acquired a row lock that was already held.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        } finally {
            $other->rollBack();
            DB::rollBack();
        }
    }
}
