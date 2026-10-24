<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

class WorkflowSafetyTest extends TestCase
{
    public function test_manual_siteground_workflows_do_not_embed_keys_or_run_on_pull_requests(): void
    {
        foreach (['hosting-check.yml', 'deploy.yml'] as $workflow) {
            $contents = (string) file_get_contents(base_path('.github/workflows/'.$workflow));

            $this->assertStringContainsString('workflow_dispatch', $contents);
            $this->assertStringNotContainsString('pull_request:', $contents);
            if ($workflow === 'deploy.yml') {
                $this->assertStringContainsString('Back up the application database', $contents);
                $this->assertStringContainsString('gunzip -t', $contents);
                $this->assertStringNotContainsString('migration_confirmation', $contents);
            }
            $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $contents);
            $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $contents);
        }

        $remote = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));
        $this->assertStringContainsString('app.reserveworx.com', $remote);
        $this->assertStringContainsString('laravel/shared/backups', $remote);
        $this->assertStringContainsString('gunzip -t', $remote);
        $this->assertStringNotContainsString('public_html/app.reserveworx.com', $remote);
        $this->assertStringContainsString('Successful purge', $remote);
        $this->assertStringContainsString('HTTP 202', $remote);
        $this->assertStringContainsString('2>/dev/null)" || continue', $remote);
        $this->assertStringContainsString("tr '[:upper:]' '[:lower:]'", $remote);

        $this->assertStringContainsString('chmod 600', $remote);

        $prepare = (string) file_get_contents(base_path('scripts/prepare-siteground-ssh.sh'));
        $this->assertStringContainsString('sg_key.pub', $prepare);
        $this->assertStringContainsString('IdentitiesOnly=yes', $prepare);
    }

    public function test_backup_action_writes_a_private_gzip_and_rejects_an_empty_stream(): void
    {
        $home = sys_get_temp_dir().'/rw-dump-'.bin2hex(random_bytes(4));
        $bin = $home.'/bin';
        $shared = $home.'/www/app.reserveworx.com/laravel/shared';
        $backups = $shared.'/backups';
        mkdir($bin, 0777, true);
        mkdir($shared, 0777, true);
        file_put_contents($shared.'/.env', implode("\n", [
            'DB_HOST="127.0.0.1"',
            'DB_PORT="3306"',
            'DB_DATABASE="reserveworx"',
            'DB_USERNAME="reserveworx"',
            'DB_PASSWORD="backup\\"secret"',
        ])."\n");
        $old = $backups;
        mkdir($old, 0777, true);
        $stale = $old.'/20000101T000000Z.sql.gz';
        file_put_contents($stale, (string) gzencode("-- MySQL dump\nCREATE TABLE stale (id int);\n"));
        chmod($stale, 0600);
        touch($stale, 946684800);
        $also = $old.'/20000102T000000Z.sql.gz';
        file_put_contents($also, (string) gzencode("-- MySQL dump\nCREATE TABLE older (id int);\n"));
        chmod($also, 0600);
        touch($also, 946771200);

        $dump = $bin.'/mysqldump';
        file_put_contents($dump, <<<'BASH'
#!/bin/bash
if [[ "${1:-}" == "--help" ]]; then
  exit 0
fi
printf '%s\n' '-- MySQL dump 10.13' 'CREATE TABLE bookings (id int);' '-- Dump completed'
BASH);
        chmod($dump, 0755);

        $ran = $this->runBackup($home, $bin);
        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('gunzip_test=ok', $ran['output']);
        $this->assertStringNotContainsString('backup\\"secret', $ran['output']);
        $this->assertStringNotContainsString('backup"secret', $ran['output']);
        $this->assertStringContainsString('backup_pruned=1', $ran['output']);
        $this->assertFileDoesNotExist($stale);

        preg_match('/^backup_path=(.+)$/m', $ran['output'], $match);
        $this->assertArrayHasKey(1, $match);
        $path = trim($match[1]);
        $this->assertMatchesRegularExpression('#/www/app\.reserveworx\.com/laravel/shared/backups/[0-9]{8}T[0-9]{6}Z\.sql\.gz$#', $path);
        $this->assertFileExists($path);
        $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertGreaterThan(0, filesize($path));
        exec('gunzip -t '.escapeshellarg($path).' 2>&1', $gunzipOut, $gunzipExit);
        $this->assertSame(0, $gunzipExit, implode("\n", $gunzipOut));

        file_put_contents($dump, "#!/bin/bash\nexit 0\n");
        chmod($dump, 0755);
        $empty = $this->runBackup($home.'-empty', $bin, $shared.'/.env');
        $this->assertNotSame(0, $empty['exit'], $empty['output']);
        $this->assertStringContainsString('backup=failed', $empty['output']);
        $emptyDir = $home.'-empty/www/app.reserveworx.com/laravel/shared/backups';
        $left = glob($emptyDir.'/*.sql.gz');
        $this->assertSame([], $left === false ? ['glob-failed'] : $left);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runBackup(string $home, string $bin, ?string $envSource = null): array
    {
        $shared = $home.'/www/app.reserveworx.com/laravel/shared';
        if (! is_dir($shared)) {
            mkdir($shared, 0777, true);
        }
        if ($envSource !== null) {
            copy($envSource, $shared.'/.env');
        }
        $script = base_path('scripts/remote-deploy.sh');
        $command = 'export HOME='.escapeshellarg($home)
            .' PATH='.escapeshellarg($bin.':/usr/bin:/bin')
            .' ACTION=backup RW_DEPLOY_LIBRARY=; bash '.escapeshellarg($script);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(['bash', '-lc', $command], $descriptors, $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    public function test_ci_declares_the_required_checks(): void
    {
        $contents = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('pull_request:', $contents);
        $this->assertStringContainsString('php-version: "8.2"', $contents);
        $this->assertStringContainsString('mysql:8.0', $contents);
        $this->assertStringContainsString('mariadb:11.8', $contents);
        $this->assertStringContainsString('pint', $contents);
        $this->assertStringContainsString('phpstan', $contents);
        $this->assertStringContainsString('composer audit', $contents);
    }
}
