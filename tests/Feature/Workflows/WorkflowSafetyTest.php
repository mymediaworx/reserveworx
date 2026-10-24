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
                $this->assertStringContainsString("push:\n    branches:\n      - main", $contents);
                $this->assertStringContainsString('Back up the application database', $contents);
                $this->assertStringContainsString('gunzip -t', $contents);
                $this->assertStringNotContainsString('migration_confirmation', $contents);
                $this->assertStringNotContainsString('http://127.0.0.1/up', $contents);
                $this->assertStringNotContainsString('http://localhost', $contents);
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
        $this->assertStringContainsString('rollback_probe=https://app.reserveworx.com/up', $remote);
        $this->assertStringContainsString('https://app.reserveworx.com/platform', $remote);
        $this->assertStringContainsString('reserveworx:grant-platform-admin michael@brandworx.ai', $remote);
        $this->assertStringNotContainsString('http://127.0.0.1/up', $remote);
        $this->assertStringNotContainsString('http://localhost/up', $remote);
        $this->assertStringContainsString('harbor.reserveworx.com', $remote);
        $this->assertStringContainsString('reserveworx:attach-verified-domain harbor-demo harbor.reserveworx.com', $remote);
        $this->assertStringContainsString('Team sign in', $remote);
        $this->assertStringContainsString('https://harbor.reserveworx.com/', $remote);
        $this->assertStringContainsString('Harbor Demo bookings', $remote);
        $this->assertStringContainsString('<h1>Harbor Demo</h1>', $remote);
        $this->assertStringContainsString('Single kayak rental', $remote);
        $this->assertStringContainsString('db:seed --class=DemoShopSeeder --force', $remote);
        $this->assertStringContainsString('reserveworx:grant-demo-owner michael@brandworx.ai harbor-demo', $remote);
        $this->assertStringNotContainsString('artisan db:seed --force', $remote);

        $ssh = (string) file_get_contents(base_path('scripts/ssh-siteground.sh'));
        $this->assertStringContainsString('ssh_connect_retry', $ssh);
        $this->assertStringContainsString('kex_exchange_identification', $ssh);
        $this->assertStringNotContainsString('www/reserveworx.com', $ssh);

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

    public function test_a_dropped_ssh_handshake_is_retried_and_a_started_command_is_not(): void
    {
        $dir = sys_get_temp_dir().'/rw-ssh-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $fake = $dir.'/fake-ssh';
        $count = $dir.'/count';
        file_put_contents($fake, <<<'BASH'
#!/bin/bash
n=0
if [[ -f "$RW_SSH_COUNT" ]]; then
  n="$(cat "$RW_SSH_COUNT")"
fi
n=$((n + 1))
printf '%s' "$n" > "$RW_SSH_COUNT"
mode="${RW_SSH_FAKE_MODE:-reset-then-ok}"
if [[ "$mode" == "reset-then-ok" && "$n" -eq 1 ]]; then
  echo "kex_exchange_identification: read: Connection reset by peer" >&2
  exit 255
fi
if [[ "$mode" == "started" ]]; then
  echo "started"
  echo "kex_exchange_identification: read: Connection reset by peer" >&2
  exit 255
fi
if [[ "$mode" == "remote-failed" ]]; then
  echo "started"
  exit 1
fi
cat
echo "session-open"
exit 0
BASH);
        chmod($fake, 0755);
        $script = base_path('scripts/ssh-siteground.sh');
        $base = 'export SG_SSH_BIN='.escapeshellarg($fake)
            .' RW_SSH_COUNT='.escapeshellarg($count)
            .' RW_SSH_RETRY_SLEEP=0 RW_SSH_LIBRARY=1; source '.escapeshellarg($script).'; ';

        $retried = $this->runShell($base.'printf payload | ssh_with_retry');
        $this->assertSame(0, $retried['exit'], $retried['output']);
        $this->assertStringContainsString('ssh_connect_retry attempt=1', $retried['output']);
        $this->assertStringContainsString('payloadsession-open', $retried['output']);
        $this->assertSame('2', (string) file_get_contents($count));

        file_put_contents($count, '0');
        $started = $this->runShell($base.'RW_SSH_FAKE_MODE=started ssh_with_retry');
        $this->assertSame(255, $started['exit'], $started['output']);
        $this->assertStringContainsString('started', $started['output']);
        $this->assertStringNotContainsString('ssh_connect_retry', $started['output']);
        $this->assertSame('1', (string) file_get_contents($count));

        file_put_contents($count, '0');
        $failed = $this->runShell($base.'RW_SSH_FAKE_MODE=remote-failed ssh_with_retry');
        $this->assertSame(1, $failed['exit'], $failed['output']);
        $this->assertStringNotContainsString('ssh_connect_retry', $failed['output']);
        $this->assertSame('1', (string) file_get_contents($count));
    }

    public function test_harbor_front_controller_uses_the_live_app_and_leaves_the_main_site_alone(): void
    {
        $home = sys_get_temp_dir().'/rw-harbor-'.bin2hex(random_bytes(4));
        $harbor = $home.'/www/harbor.reserveworx.com/public_html';
        $main = $home.'/www/reserveworx.com/public_html';
        $app = $home.'/www/app.reserveworx.com';
        mkdir($harbor, 0777, true);
        mkdir($main, 0777, true);
        mkdir($app.'/public_html', 0777, true);
        mkdir($app.'/laravel/current/public', 0777, true);
        file_put_contents($main.'/index.html', 'main-site');
        file_put_contents($app.'/public_html/.htaccess', "RewriteEngine On\n");

        $script = base_path('scripts/remote-deploy.sh');
        $boot = $app.'/laravel/current';
        $command = 'export HOME='.escapeshellarg($home)
            .' ACTION=deploy RW_DEPLOY_LIBRARY=1; source '.escapeshellarg($script)
            .'; harbor_docroot_verified; install_harbor_front_controller';
        $ran = $this->runShell($command);
        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('harbor_docroot='.$harbor, $ran['output']);
        $this->assertStringContainsString('harbor_front_controller=absolute '.$boot, $ran['output']);
        $index = (string) file_get_contents($harbor.'/index.php');
        $this->assertStringContainsString("\$root = '".$boot."';", $index);
        $this->assertStringNotContainsString('www/reserveworx.com', $index);
        $this->assertSame("RewriteEngine On\n", (string) file_get_contents($harbor.'/.htaccess'));
        $this->assertSame('main-site', (string) file_get_contents($main.'/index.html'));

        unlink($harbor.'/index.php');
        unlink($harbor.'/.htaccess');
        rmdir($harbor);
        $missing = $this->runShell(
            'export HOME='.escapeshellarg($home)
            .' ACTION=deploy RW_DEPLOY_LIBRARY=1; source '.escapeshellarg($script)
            .'; harbor_docroot_verified',
        );
        $this->assertNotSame(0, $missing['exit']);
        $this->assertStringContainsString('harbor_docroot=missing', $missing['output']);
        $this->assertStringContainsString('www_entry=reserveworx.com', $missing['output']);
        $this->assertSame('main-site', (string) file_get_contents($main.'/index.html'));
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runShell(string $command): array
    {
        $process = proc_open(['bash', '-lc', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
