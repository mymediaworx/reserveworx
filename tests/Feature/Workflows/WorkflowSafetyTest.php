<?php

namespace Tests\Feature\Workflows;

use Dotenv\Dotenv;
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
        $this->assertStringContainsString('reserveworx:mail-status', $remote);
        $this->assertStringNotContainsString('artisan db:seed --force', $remote);
        $mailConfigure = strpos($remote, 'if ! configure_production_mail; then');
        $releaseSwitch = strpos($remote, "\ndrop_old_rollback\n");
        $configCache = strpos($remote, 'artisan config:cache');
        $mailStatus = strpos($remote, 'reserveworx:mail-status');
        $this->assertNotFalse($mailConfigure);
        $this->assertNotFalse($releaseSwitch);
        $this->assertNotFalse($configCache);
        $this->assertNotFalse($mailStatus);
        $this->assertLessThan($releaseSwitch, $mailConfigure);
        $this->assertLessThan($configCache, $releaseSwitch);
        $this->assertLessThan($mailStatus, $configCache);
        $this->assertStringContainsString('Mail settings were not written. Document root was not changed.', $remote);
        $this->assertStringContainsString('mail_configured=no', $remote);
        $this->assertStringContainsString("MAIL_HOST' => 'mail.reserveworx.com'", $remote);
        $this->assertStringContainsString("MAIL_PORT' => '465'", $remote);
        $this->assertStringContainsString("MAIL_SCHEME' => 'smtps'", $remote);
        $this->assertStringContainsString("MAIL_USERNAME' => 'noreply@reserveworx.com'", $remote);
        $this->assertStringContainsString("MAIL_FROM_ADDRESS' => 'noreply@reserveworx.com'", $remote);
        $this->assertStringContainsString("MAIL_FROM_NAME' => 'ReserveWorx'", $remote);

        $deploy = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $this->assertStringContainsString('secrets.MAIL_PASSWORD', $deploy);
        $this->assertStringContainsString('::add-mask::${MAIL_PASSWORD}', $deploy);
        $this->assertStringContainsString('::add-mask::${RW_MAIL_PASSWORD}', $deploy);
        $this->assertStringContainsString('scripts/accept-mail-header.sh', $deploy);
        $this->assertStringContainsString('base64 -w0', $deploy);
        $this->assertStringContainsString("printf 'MAIL -\\n'", $deploy);
        $this->assertStringNotContainsString('RW_MAIL_PASSWORD=%q', $deploy);
        $this->assertStringNotContainsString('echo "$RW_MAIL_PASSWORD"', $deploy);
        $this->assertStringNotContainsString('echo "${RW_MAIL_PASSWORD}"', $deploy);
        $this->assertStringNotContainsString('cat > ${mail_file}', $deploy);

        $mailHeader = (string) file_get_contents(base_path('scripts/accept-mail-header.sh'));
        $this->assertStringContainsString('www/app.reserveworx.com/laravel/shared/.mail-password', $mailHeader);
        $this->assertStringContainsString('www/reserveworx.com', $mailHeader);
        $this->assertStringContainsString('exec bash -s', $mailHeader);
        $this->assertStringNotContainsString('echo "$payload"', $mailHeader);
        $this->assertStringNotContainsString('echo "$mail_header"', $mailHeader);

        $ssh = (string) file_get_contents(base_path('scripts/ssh-siteground.sh'));
        $this->assertStringNotContainsString('ssh_connect_retry', $ssh);
        $this->assertStringNotContainsString('kex_exchange_identification', $ssh);
        $this->assertStringNotContainsString('www/reserveworx.com', $ssh);

        $prepare = (string) file_get_contents(base_path('scripts/prepare-siteground-ssh.sh'));
        $this->assertStringContainsString('sg_key.pub', $prepare);
        $this->assertStringContainsString('IdentitiesOnly=yes', $prepare);
        $this->assertStringContainsString('ControlMaster=auto', $prepare);
        $this->assertStringContainsString('ControlPersist=600', $prepare);
        $this->assertStringContainsString('ssh_handshake=1', $prepare);
        $this->assertStringContainsString('Host key fingerprint:', $prepare);
        $this->assertStringNotContainsString('ssh-keyscan', $prepare);
        $this->assertStringNotContainsString('ssh_connect_retry', $prepare);

        $this->assertStringContainsString('Upload release archive', $deploy);
        $this->assertStringContainsString('Activate release', $deploy);
        $this->assertStringContainsString('SSH handshakes opened:', $deploy);
        $activate = strpos($deploy, "name: Activate release\n");
        $hosting = strpos($deploy, "name: Hosting check\n");
        $this->assertNotFalse($activate);
        $this->assertNotFalse($hosting);
        $this->assertLessThan($hosting, $activate);
        $this->assertStringContainsString('scripts/hosting-check-artisan.sh', $deploy);
        $this->assertStringContainsString('if [[ "$status" -ne 0 || "$ACTION" != "deploy" ]]; then', $deploy);
        $this->assertStringNotContainsString('ssh_connect_retry', $deploy);

        $wrapper = (string) file_get_contents(base_path('scripts/hosting-check-artisan.sh'));
        $this->assertStringContainsString('RW_DEPLOY_LIBRARY=1', $wrapper);
        $this->assertStringContainsString('reserveworx:hosting-check --no-interaction', $wrapper);
        $this->assertStringContainsString('find_php', $wrapper);
        $this->assertStringContainsString('www/app.reserveworx.com/laravel/current', $wrapper);
        $this->assertStringNotContainsString('ssh_connect_retry', $wrapper);
        $this->assertStringNotContainsString('www/reserveworx.com', $wrapper);

        $workflows = glob(base_path('.github/workflows/*'));
        $this->assertIsArray($workflows);
        $names = array_map('basename', $workflows);
        sort($names);
        $this->assertSame(['ci.yml', 'deploy.yml', 'hosting-check.yml'], $names);
    }

    public function test_the_hosting_check_runs_on_the_active_release_without_printing_secrets(): void
    {
        $home = sys_get_temp_dir().'/rw-hosting-'.bin2hex(random_bytes(4));
        $bin = $home.'/bin';
        $current = $home.'/www/app.reserveworx.com/laravel/current';
        $main = $home.'/www/reserveworx.com/public_html';
        mkdir($bin, 0777, true);
        mkdir($current.'/scripts', 0777, true);
        mkdir($main, 0777, true);
        copy(base_path('scripts/remote-deploy.sh'), $current.'/scripts/remote-deploy.sh');
        file_put_contents($main.'/index.html', 'main-site');
        file_put_contents($bin.'/php', <<<'BASH'
#!/bin/bash
if [[ "${1:-}" == "-r" ]]; then
  printf '8.2'
  exit 0
fi
if [[ "${1:-}" == "artisan" && "${2:-}" == "reserveworx:hosting-check" && "${3:-}" == "--no-interaction" ]]; then
  printf 'hosting_check_invoked=yes\n'
  exit 0
fi
printf 'unexpected php invocation\n' >&2
exit 1
BASH);
        chmod($bin.'/php', 0755);

        $secret = 'rw-hosting-wrapper-secret';
        $command = 'export HOME='.escapeshellarg($home)
            .' PATH='.escapeshellarg($bin.':/usr/bin:/bin')
            .' SG_SSH_KEY='.escapeshellarg($secret)
            .' DB_PASSWORD='.escapeshellarg($secret)
            .'; bash '.escapeshellarg(base_path('scripts/hosting-check-artisan.sh'));
        $ran = $this->runShell($command);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('hosting_check_invoked=yes', $ran['output']);
        $this->assertStringNotContainsString($secret, $ran['output']);
        $this->assertSame('main-site', (string) file_get_contents($main.'/index.html'));
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

    public function test_siteground_ssh_reuses_one_connection_across_commands(): void
    {
        $dir = sys_get_temp_dir().'/rw-ssh-'.bin2hex(random_bytes(4));
        $home = $dir.'/home';
        $bin = $dir.'/bin';
        mkdir($home, 0777, true);
        mkdir($bin, 0777, true);
        $key = $dir.'/id_ed25519';
        $connects = $dir.'/connects';
        $argvLog = $dir.'/argv.log';
        exec('ssh-keygen -q -t ed25519 -f '.escapeshellarg($key).' -N ""', $keyOut, $keyExit);
        $this->assertSame(0, $keyExit, implode("\n", $keyOut));

        file_put_contents($bin.'/ssh', <<<'PY'
#!/usr/bin/env python3
import os, sys, socket
args = sys.argv[1:]
control = None
mode = None
i = 0
while i < len(args):
    arg = args[i]
    if arg == "--":
        i += 1
        continue
    if arg == "-o" and i + 1 < len(args):
        opt = args[i + 1]
        if opt.startswith("ControlPath="):
            control = opt.split("=", 1)[1]
        i += 2
        continue
    if arg == "-O" and i + 1 < len(args):
        mode = args[i + 1]
        i += 2
        continue
    if arg in ("-p", "-i", "-l") and i + 1 < len(args):
        i += 2
        continue
    i += 1
with open(os.environ["RW_SSH_ARGV_LOG"], "a") as handle:
    handle.write(" ".join(args) + "\n")
if mode == "check":
    if os.environ.get("RW_SSH_STALE") == "1" or not control or not os.path.exists(control):
        sys.exit(1)
    sys.exit(0)
if mode == "exit":
    if control and os.path.exists(control):
        os.remove(control)
    sys.exit(0)
if mode is not None:
    sys.exit(1)
text = " ".join(args)
for needle in ("ControlMaster=auto", "ControlPersist=600"):
    if needle not in text:
        sys.stderr.write("missing %s\n" % needle)
        sys.exit(3)
if not control:
    sys.stderr.write("missing control\n")
    sys.exit(2)
if not os.path.exists(control):
    sock = socket.socket(socket.AF_UNIX)
    sock.bind(control)
    sock.close()
path = os.environ["RW_SSH_CONNECTS"]
previous = "0"
if os.path.exists(path):
    previous = open(path).read().strip() or "0"
open(path, "w").write(str(int(previous) + 1))
sys.stdout.buffer.write(sys.stdin.buffer.read())
sys.stdout.write("session-open\n")
PY);
        chmod($bin.'/ssh', 0755);
        file_put_contents($bin.'/ssh-keyscan', "#!/bin/sh\necho ssh-keyscan-called >&2\nexit 99\n");
        chmod($bin.'/ssh-keyscan', 0755);

        $script = base_path('scripts/ssh-siteground.sh');
        $private = (string) file_get_contents($key);
        $base = 'export HOME='.escapeshellarg($home)
            .' PATH='.escapeshellarg($bin.':/usr/bin:/bin:/exec-daemon')
            .' RW_SSH_CONNECTS='.escapeshellarg($connects)
            .' RW_SSH_ARGV_LOG='.escapeshellarg($argvLog)
            .' SG_HOST=127.0.0.1 SG_USER=deploy SG_PORT=2222'
            .' SG_SSH_KEY='.escapeshellarg($private)
            .'; ';

        $first = $this->runShell($base.'printf alpha | '.escapeshellarg($script).' deploy@127.0.0.1 true');
        $this->assertSame(0, $first['exit'], $first['output']);
        $this->assertStringContainsString('alphasession-open', $first['output']);
        $this->assertStringContainsString('ssh_handshake=1', $first['output']);
        $this->assertStringNotContainsString('ssh_connect_retry', $first['output']);
        $this->assertStringNotContainsString('ssh-keyscan-called', $first['output']);
        $this->assertStringNotContainsString($private, $first['output']);

        $second = $this->runShell($base.'printf beta | '.escapeshellarg($script).' deploy@127.0.0.1 true');
        $this->assertSame(0, $second['exit'], $second['output']);
        $this->assertStringContainsString('betasession-open', $second['output']);
        $this->assertStringContainsString('ssh_handshake=0', $second['output']);
        $this->assertStringNotContainsString('ssh_handshake=1', $second['output']);
        $this->assertSame('2', trim((string) file_get_contents($connects)));
        $this->assertSame(
            "ssh_handshake=1\nssh_handshake=0\n",
            (string) file_get_contents($home.'/.ssh/sg-handshakes.log'),
        );

        $stale = $this->runShell($base.'export RW_SSH_STALE=1; printf gamma | '.escapeshellarg($script).' deploy@127.0.0.1 true');
        $this->assertSame(0, $stale['exit'], $stale['output']);
        $this->assertStringContainsString('gammasession-open', $stale['output']);
        $this->assertStringContainsString('ssh_handshake=1', $stale['output']);
        $this->assertSame('3', trim((string) file_get_contents($connects)));
        $this->assertStringNotContainsString('ssh-keyscan', (string) file_get_contents($argvLog));

        $closed = $this->runShell($base.escapeshellarg($script).' -O exit deploy@127.0.0.1');
        $this->assertSame(0, $closed['exit'], $closed['output']);
        $this->assertFileDoesNotExist($home.'/.ssh/sg-cm.sock');
        $this->assertSame(
            "ssh_handshake=1\nssh_handshake=0\nssh_handshake=1\n",
            (string) file_get_contents($home.'/.ssh/sg-handshakes.log'),
        );
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

    public function test_a_mail_password_is_written_into_shared_env_and_is_not_printed(): void
    {
        $password = 'p@ss\'w\\ord$foo"x';
        $ran = $this->configureMail($password."\n");

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('mail_configured=yes', $ran['output']);
        $this->assertStringNotContainsString($password, $ran['output']);
        $this->assertStringNotContainsString('old-mail-secret', $ran['output']);
        $this->assertFileDoesNotExist($ran['password_file']);
        $this->assertSame('600', substr(sprintf('%o', fileperms($ran['env'])), -3));
        $this->assertSame('main-site', (string) file_get_contents($ran['main']));
        $this->assertFileDoesNotExist(dirname($ran['main']).'/.env');

        $values = Dotenv::parse((string) file_get_contents($ran['env']));
        $this->assertSame('smtp', $values['MAIL_MAILER']);
        $this->assertSame('mail.reserveworx.com', $values['MAIL_HOST']);
        $this->assertSame('465', $values['MAIL_PORT']);
        $this->assertSame('smtps', $values['MAIL_SCHEME']);
        $this->assertSame('noreply@reserveworx.com', $values['MAIL_USERNAME']);
        $this->assertSame('noreply@reserveworx.com', $values['MAIL_FROM_ADDRESS']);
        $this->assertSame('ReserveWorx', $values['MAIL_FROM_NAME']);
        $this->assertSame($password, $values['MAIL_PASSWORD']);
        $this->assertSame('keep-db', $values['DB_PASSWORD']);
    }

    public function test_a_missing_mail_password_leaves_the_log_mailer_and_does_not_fail(): void
    {
        $ran = $this->configureMail(null);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('mail_configured=no', $ran['output']);
        $this->assertStringNotContainsString('old-mail-secret', $ran['output']);
        $this->assertStringNotContainsString('mail_configured=yes', $ran['output']);
        $this->assertSame('main-site', (string) file_get_contents($ran['main']));

        $values = Dotenv::parse((string) file_get_contents($ran['env']));
        $this->assertSame('log', $values['MAIL_MAILER']);
        $this->assertSame('keep-db', $values['DB_PASSWORD']);
        $this->assertSame('old-mail-secret', $values['MAIL_PASSWORD']);
        $this->assertArrayNotHasKey('MAIL_HOST', $values);

        $empty = $this->configureMail('');
        $this->assertSame(0, $empty['exit'], $empty['output']);
        $this->assertStringContainsString('mail_configured=no', $empty['output']);
        $this->assertStringNotContainsString('old-mail-secret', $empty['output']);
        $emptyValues = Dotenv::parse((string) file_get_contents($empty['env']));
        $this->assertSame('log', $emptyValues['MAIL_MAILER']);
    }

    public function test_a_mail_password_with_a_newline_is_rejected_without_being_printed(): void
    {
        $ran = $this->configureMail("bad\nsecret");

        $this->assertNotSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('mail_env=rejected', $ran['output']);
        $this->assertStringNotContainsString('bad', $ran['output']);
        $this->assertStringNotContainsString('secret', $ran['output']);
        $this->assertFileDoesNotExist($ran['password_file']);

        $values = Dotenv::parse((string) file_get_contents($ran['env']));
        $this->assertSame('log', $values['MAIL_MAILER']);
        $this->assertSame('keep-db', $values['DB_PASSWORD']);
        $this->assertSame('main-site', (string) file_get_contents($ran['main']));
    }

    public function test_the_mail_header_is_stored_on_the_same_session_without_printing_it(): void
    {
        $password = 'p@ss\'w\\ord$foo"x';
        $ran = $this->acceptMailHeader('MAIL '.base64_encode($password), "echo deploy-started\n");

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('deploy-started', $ran['output']);
        $this->assertStringNotContainsString($password, $ran['output']);
        $this->assertStringNotContainsString(base64_encode($password), $ran['output']);
        $this->assertSame($password, (string) file_get_contents($ran['password_file']));
        $this->assertSame('600', substr(sprintf('%o', fileperms($ran['password_file'])), -3));
        $this->assertSame('main-site', (string) file_get_contents($ran['main']));
        $this->assertFileDoesNotExist(dirname($ran['main']).'/.env');

        $cleared = $this->acceptMailHeader('MAIL -', "echo cleared\n");
        $this->assertSame(0, $cleared['exit'], $cleared['output']);
        $this->assertStringContainsString('cleared', $cleared['output']);
        $this->assertFileDoesNotExist($cleared['password_file']);
        $this->assertSame('main-site', (string) file_get_contents($cleared['main']));
    }

    /**
     * @return array{exit: int, output: string, password_file: string, main: string}
     */
    private function acceptMailHeader(string $header, string $script): array
    {
        $home = sys_get_temp_dir().'/rw-mailhdr-'.bin2hex(random_bytes(4));
        $shared = $home.'/www/app.reserveworx.com/laravel/shared';
        $main = $home.'/www/reserveworx.com/public_html';
        mkdir($shared, 0777, true);
        mkdir($main, 0777, true);
        file_put_contents($main.'/index.html', 'main-site');
        file_put_contents($shared.'/.mail-password', 'stale');

        $command = 'export HOME='.escapeshellarg($home)
            .'; printf %s '.escapeshellarg($header."\n".$script)
            .' | bash '.escapeshellarg(base_path('scripts/accept-mail-header.sh'));
        $ran = $this->runShell($command);

        return [
            'exit' => $ran['exit'],
            'output' => $ran['output'],
            'password_file' => $shared.'/.mail-password',
            'main' => $main.'/index.html',
        ];
    }

    /**
     * @return array{exit: int, output: string, env: string, password_file: string, main: string}
     */
    private function configureMail(?string $password): array
    {
        $home = sys_get_temp_dir().'/rw-mail-'.bin2hex(random_bytes(4));
        $shared = $home.'/www/app.reserveworx.com/laravel/shared';
        $main = $home.'/www/reserveworx.com/public_html';
        mkdir($shared, 0777, true);
        mkdir($main, 0777, true);
        $env = $shared.'/.env';
        $passwordFile = $shared.'/.mail-password';
        file_put_contents($main.'/index.html', 'main-site');
        file_put_contents($env, implode("\n", [
            'APP_NAME="ReserveWorx"',
            'MAIL_MAILER="log"',
            'DB_PASSWORD="keep-db"',
            'MAIL_PASSWORD="old-mail-secret"',
        ])."\n");
        if ($password !== null) {
            file_put_contents($passwordFile, $password);
        }

        $script = base_path('scripts/remote-deploy.sh');
        $command = 'export HOME='.escapeshellarg($home)
            .' ACTION=deploy RW_DEPLOY_LIBRARY=1; source '.escapeshellarg($script)
            .'; configure_production_mail';
        $ran = $this->runShell($command);

        return [
            'exit' => $ran['exit'],
            'output' => $ran['output'],
            'env' => $env,
            'password_file' => $passwordFile,
            'main' => $main.'/index.html',
        ];
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
