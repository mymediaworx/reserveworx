<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MysqlVersionTest extends TestCase
{
    public function test_the_suite_runs_on_mysql_8_or_mariadb_11(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());

        $version = DB::selectOne('select version() as version');

        $this->assertMatchesRegularExpression('/^(8\.|11\.)/', (string) $version->version);
    }
}
