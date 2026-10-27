<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

class InodeGuardTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SHA_C = 'cccccccccccccccccccccccccccccccccccccccc';

    /** @var list<string> */
    private array $homes = [];

    protected function tearDown(): void
    {
        foreach ($this->homes as $home) {
            exec('rm -rf '.escapeshellarg($home));
        }

        parent::tearDown();
    }

    private function home(): string
    {
        $home = sys_get_temp_dir().'/rw-guard-'.bin2hex(random_bytes(4));
        $laravel = $home.'/www/app.reserveworx.com/laravel';
        foreach (['releases', 'incoming', 'shared/storage/framework/cache/data', 'shared/storage/framework/sessions', 'shared/storage/framework/views', 'shared/storage/logs', 'shared/storage/app/public'] as $dir) {
            mkdir($laravel.'/'.$dir, 0777, true);
        }
        $this->homes[] = $home;

        return $home;
    }

    private function laravel(string $home): string
    {
        return $home.'/www/app.reserveworx.com/laravel';
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function sh(string $command): array
    {
        $process = proc_open(['bash', '-lc', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'output' => $output];
    }

    /**
     * @param  array<string, string>  $env
     * @return array{exit: int, output: string}
     */
    private function lib(string $home, string $function, array $env = []): array
    {
        $exports = 'export HOME='.escapeshellarg($home).' ACTION=deploy RW_DEPLOY_LIBRARY=1';
        foreach ($env as $key => $value) {
            $exports .= ' '.$key.'='.escapeshellarg($value);
        }

        return $this->sh($exports.'; source '.escapeshellarg(base_path('scripts/remote-deploy.sh')).'; '.$function);
    }

    private function inodes(string $home): int
    {
        return (int) trim($this->sh('du --inodes -s -x '.escapeshellarg($home).' | cut -f1')['output']);
    }

    public function test_guard_passes_below_the_warn_level(): void
    {
        $home = $this->home();
        $count = $this->inodes($home);

        $ran = $this->lib($home, 'inode_guard', ['RW_INODE_WARN' => (string) ($count + 100), 'RW_INODE_ABORT' => (string) ($count + 200)]);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('inode_guard=ok', $ran['output']);
        $this->assertStringContainsString('inode_guard_home='.$count, $ran['output']);
    }

    public function test_guard_warns_but_continues_between_warn_and_abort_and_adds_the_external_allowance(): void
    {
        $home = $this->home();
        $count = $this->inodes($home);

        $ran = $this->lib($home, 'inode_guard', [
            'RW_INODE_EXTERNAL' => '100',
            'RW_INODE_WARN' => (string) ($count + 50),
            'RW_INODE_ABORT' => (string) ($count + 500),
        ]);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('inode_guard_external=100', $ran['output']);
        $this->assertStringContainsString('inode_guard_total='.($count + 100), $ran['output']);
        $this->assertStringContainsString('inode_guard=warn', $ran['output']);
    }

    public function test_guard_aborts_at_the_abort_level_including_the_boundary(): void
    {
        $home = $this->home();
        $count = $this->inodes($home);

        $over = $this->lib($home, 'inode_guard', ['RW_INODE_EXTERNAL' => '1000', 'RW_INODE_ABORT' => (string) ($count + 500)]);
        $this->assertSame(1, $over['exit'], $over['output']);
        $this->assertStringContainsString('inode_guard=abort', $over['output']);

        $exact = $this->lib($home, 'inode_guard', ['RW_INODE_EXTERNAL' => '500', 'RW_INODE_ABORT' => (string) ($count + 500)]);
        $this->assertSame(1, $exact['exit'], $exact['output']);

        $under = $this->lib($home, 'inode_guard', ['RW_INODE_EXTERNAL' => '499', 'RW_INODE_ABORT' => (string) ($count + 500)]);
        $this->assertSame(0, $under['exit'], $under['output']);
    }

    public function test_guard_defaults_are_450000_warn_540000_abort_of_600000_and_a_bad_allowance_is_zero(): void
    {
        $home = $this->home();

        $ran = $this->lib($home, 'inode_guard', ['RW_INODE_EXTERNAL' => 'lots']);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('inode_guard_external=0', $ran['output']);
        $this->assertStringContainsString('inode_guard_warn=450000 abort=540000 cap=600000', $ran['output']);

        $huge = $this->lib($home, 'inode_guard', ['RW_INODE_EXTERNAL' => '540000']);
        $this->assertSame(1, $huge['exit'], $huge['output']);
    }

    public function test_guard_counts_the_whole_account_not_just_the_app(): void
    {
        $script = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));

        $this->assertStringContainsString('du --inodes -s -x "$HOME"', $script);
        $this->assertStringContainsString('RW_INODE_EXTERNAL', $script);

        $home = $this->home();
        mkdir($home.'/other-site', 0777, true);
        for ($i = 0; $i < 30; $i++) {
            file_put_contents($home.'/other-site/f'.$i, 'x');
        }
        $count = $this->inodes($home);
        $appOnly = (int) trim($this->sh('du --inodes -s -x '.escapeshellarg($home.'/www/app.reserveworx.com').' | cut -f1')['output']);
        $this->assertGreaterThan($appOnly + 29, $count);

        $ran = $this->lib($home, 'inode_guard', ['RW_INODE_WARN' => (string) ($count + 100), 'RW_INODE_ABORT' => (string) ($count + 200)]);
        $this->assertStringContainsString('inode_guard_home='.$count, $ran['output']);
    }

    public function test_warn_level_clears_stale_regenerable_files_but_keeps_the_archive_in_flight(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        $staleArchive = $laravel.'/incoming/'.self::SHA_B.'.tar.gz';
        $inFlight = $laravel.'/incoming/'.self::SHA_A.'.tar.gz';
        $oldLog = $laravel.'/shared/storage/logs/laravel-2020-01-01.log';
        $freshLog = $laravel.'/shared/storage/logs/laravel-2099-01-01.log';
        foreach ([$staleArchive, $inFlight, $oldLog, $freshLog] as $file) {
            file_put_contents($file, 'x');
        }
        touch($oldLog, time() - 8 * 86400);

        $ran = $this->lib($home, 'inode_guard', ['RELEASE' => self::SHA_A, 'RW_INODE_WARN' => '1', 'RW_INODE_ABORT' => '900000']);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('inode_guard_cleanup=running', $ran['output']);
        $this->assertFileDoesNotExist($staleArchive);
        $this->assertFileDoesNotExist($oldLog);
        $this->assertFileExists($inFlight);
        $this->assertFileExists($freshLog);
    }

    public function test_preflight_action_runs_the_guard_and_stops_without_touching_releases(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        mkdir($laravel.'/releases/'.self::SHA_A.'/app', 0777, true);
        symlink($laravel.'/releases/'.self::SHA_A, $laravel.'/current');
        $script = escapeshellarg(base_path('scripts/remote-deploy.sh'));

        $ok = $this->sh('HOME='.escapeshellarg($home).' ACTION=preflight bash '.$script);
        $this->assertSame(0, $ok['exit'], $ok['output']);
        $this->assertStringContainsString('inode_guard=ok', $ok['output']);
        $this->assertStringNotContainsString('deploy=complete', $ok['output']);

        $stop = $this->sh('HOME='.escapeshellarg($home).' ACTION=preflight RW_INODE_EXTERNAL=600000 bash '.$script);
        $this->assertSame(1, $stop['exit'], $stop['output']);
        $this->assertStringContainsString('inode_guard=abort', $stop['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A.'/app');
        $this->assertTrue(is_link($laravel.'/current'));
    }

    public function test_a_deploy_that_the_guard_stops_deletes_the_uploaded_archive_and_changes_nothing(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        mkdir($laravel.'/releases/'.self::SHA_B.'/app', 0777, true);
        symlink($laravel.'/releases/'.self::SHA_B, $laravel.'/current');
        $archive = $laravel.'/incoming/'.self::SHA_A.'.tar.gz';
        file_put_contents($archive, 'not really a tarball');
        file_put_contents($laravel.'/incoming/leftover.tar.gz', 'x');

        $ran = $this->sh(
            'HOME='.escapeshellarg($home).' ACTION=deploy RELEASE='.self::SHA_A.' RW_INODE_EXTERNAL=600000 bash '
            .escapeshellarg(base_path('scripts/remote-deploy.sh'))
        );

        $this->assertSame(1, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('inode_guard=abort', $ran['output']);
        $this->assertFileDoesNotExist($archive);
        $this->assertFileDoesNotExist($laravel.'/incoming/leftover.tar.gz');
        $this->assertDirectoryDoesNotExist($laravel.'/releases/'.self::SHA_A);
        $this->assertSame($laravel.'/releases/'.self::SHA_B, readlink($laravel.'/current'));
    }

    public function test_rollback_and_backup_never_run_the_guard_and_it_runs_first_in_a_deploy(): void
    {
        $script = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));

        $backupStart = strpos($script, 'if [[ "$ACTION" == "backup" ]]; then');
        $preflightStart = strpos($script, 'if [[ "$ACTION" == "preflight" ]]; then');
        $rollbackStart = strpos($script, 'if [[ "$ACTION" == "rollback" ]]; then');
        $releaseCheck = strpos($script, 'if [[ ! "${RELEASE:-}" =~ ^[0-9a-f]{40}$ ]]; then');
        $deployGuard = strpos($script, 'if ! inode_guard; then');
        $findPhp = strpos($script, 'if ! php_bin="$(find_php)"; then', $releaseCheck);
        foreach ([$backupStart, $preflightStart, $rollbackStart, $releaseCheck, $deployGuard, $findPhp] as $position) {
            $this->assertIsInt($position);
        }

        $backupBlock = substr($script, $backupStart, $preflightStart - $backupStart);
        $rollbackBlock = substr($script, $rollbackStart, $releaseCheck - $rollbackStart);
        $this->assertStringNotContainsString('inode_guard', $backupBlock);
        $this->assertStringNotContainsString('inode_guard', $rollbackBlock);
        $this->assertGreaterThan($releaseCheck, $deployGuard);
        $this->assertLessThan($findPhp, $deployGuard);
        $this->assertSame(1, substr_count($script, 'if ! inode_guard; then'));
    }

    public function test_workflow_runs_the_guard_before_the_backup_and_the_upload_for_deploys_only(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

        $preflight = strpos($workflow, '- name: Inode budget pre-flight');
        $backup = strpos($workflow, '- name: Back up the application database');
        $upload = strpos($workflow, '- name: Upload release archive');
        $activate = strpos($workflow, '- name: Activate release');
        foreach ([$preflight, $backup, $upload, $activate] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($backup, $preflight);
        $this->assertLessThan($upload, $preflight);

        $block = substr($workflow, $preflight, $backup - $preflight);
        $this->assertStringContainsString("if: github.event_name == 'workflow_run' || inputs.action == 'deploy'", $block);
        $this->assertStringContainsString('RW_INODE_EXTERNAL: ${{ vars.RW_INODE_EXTERNAL }}', $block);
        $this->assertStringContainsString('preflight', $block);
        $this->assertStringContainsString('450000', $block);
        $this->assertStringContainsString('540000', $block);
        $this->assertStringNotContainsString('rollback', $block);

        $activateBlock = substr($workflow, $activate);
        $this->assertStringContainsString('RW_INODE_EXTERNAL=%q', $activateBlock);
        $this->assertStringNotContainsString('vars.RW_INODE_EXTERNAL', substr($workflow, 0, $preflight));

        $uploadBlock = substr($workflow, $upload, $activate - $upload);
        $this->assertStringContainsString('rm -f www/app.reserveworx.com/laravel/incoming/${RELEASE}.tar.gz', $uploadBlock);
        $this->assertStringNotContainsString('sleep', $workflow);
        $this->assertStringNotContainsString('for attempt', $workflow);
    }

    public function test_prune_releases_keeps_current_and_one_rollback_and_deletes_old_shas_only(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        foreach ([self::SHA_A, self::SHA_B, self::SHA_C] as $sha) {
            mkdir($laravel.'/releases/'.$sha.'/app', 0777, true);
        }
        mkdir($laravel.'/releases/notes', 0777, true);
        symlink($laravel.'/releases/'.self::SHA_A, $laravel.'/current');
        symlink($laravel.'/releases/'.self::SHA_B, $laravel.'/previous');
        file_put_contents($laravel.'/incoming/x.tar.gz', 'x');

        $ran = $this->lib($home, 'prune_releases');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_B);
        $this->assertDirectoryDoesNotExist($laravel.'/releases/'.self::SHA_C);
        $this->assertDirectoryExists($laravel.'/releases/notes');
        $this->assertStringContainsString('prune_skipped_unrecognized=notes', $ran['output']);
        $this->assertFileDoesNotExist($laravel.'/incoming/x.tar.gz');
    }

    public function test_prune_releases_refuses_when_current_cannot_be_resolved(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        foreach ([self::SHA_A, self::SHA_B] as $sha) {
            mkdir($laravel.'/releases/'.$sha.'/app', 0777, true);
        }

        $missing = $this->lib($home, 'prune_releases');
        $this->assertSame(0, $missing['exit'], $missing['output']);
        $this->assertStringContainsString('prune_releases=skipped current release could not be resolved', $missing['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_B);

        symlink($home, $laravel.'/current');
        $outside = $this->lib($home, 'prune_releases');
        $this->assertStringContainsString('prune_releases=skipped', $outside['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_B);
        $this->assertDirectoryExists($home.'/www');

        unlink($laravel.'/current');
        symlink($laravel.'/releases/gone', $laravel.'/current');
        $dangling = $this->lib($home, 'prune_releases');
        $this->assertStringContainsString('prune_releases=skipped', $dangling['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A);
    }

    public function test_maintenance_prune_keeps_current_and_one_rollback_and_removes_a_stray_previous_directory(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        foreach ([self::SHA_A, self::SHA_B, self::SHA_C] as $i => $sha) {
            mkdir($laravel.'/releases/'.$sha.'/vendor/x', 0777, true);
            touch($laravel.'/releases/'.$sha, time() - $i * 100);
        }
        // previous is a real directory holding a full copy, as made by hand.
        mkdir($laravel.'/previous/vendor/y', 0777, true);
        symlink($laravel.'/releases/'.self::SHA_A, $laravel.'/current');
        mkdir($laravel.'/shared/backups', 0777, true);
        file_put_contents($laravel.'/shared/backups/20260101T000000Z.sql.gz', 'x');
        $old = $laravel.'/shared/storage/logs/laravel-2026-01-01.log';
        file_put_contents($old, 'x');
        touch($old, time() - 9 * 86400);
        $new = $laravel.'/shared/storage/logs/laravel-new.log';
        file_put_contents($new, 'x');

        $ran = $this->lib($home, 'maintenance_prune');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('maintenance_removed_stray_previous_dir=1', $ran['output']);
        $this->assertTrue(is_link($laravel.'/previous'), 'previous is now a symlink to one rollback');
        $this->assertSame(realpath($laravel.'/releases/'.self::SHA_B), realpath($laravel.'/previous'));
        $this->assertSame(realpath($laravel.'/releases/'.self::SHA_A), realpath($laravel.'/current'));
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A.'/vendor/x');
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_B.'/vendor/x');
        $this->assertDirectoryDoesNotExist($laravel.'/releases/'.self::SHA_C);
        $this->assertFileExists($laravel.'/shared/backups/20260101T000000Z.sql.gz');
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function test_maintenance_prune_refuses_without_a_resolvable_current_and_deletes_nothing(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        mkdir($laravel.'/releases/'.self::SHA_A, 0777, true);
        mkdir($laravel.'/releases/'.self::SHA_B, 0777, true);
        mkdir($laravel.'/previous/keep', 0777, true);

        $ran = $this->lib($home, 'maintenance_prune');

        $this->assertNotSame(0, $ran['exit']);
        $this->assertStringContainsString('maintenance_prune=refused', $ran['output']);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_A);
        $this->assertDirectoryExists($laravel.'/releases/'.self::SHA_B);
        $this->assertDirectoryExists($laravel.'/previous/keep');
    }

    public function test_maintenance_report_counts_each_release_and_flags_a_real_previous_directory_and_secret_locations(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        mkdir($laravel.'/releases/'.self::SHA_A.'/vendor/x', 0777, true);
        symlink($laravel.'/releases/'.self::SHA_A, $laravel.'/current');
        mkdir($laravel.'/previous/vendor', 0777, true);
        file_put_contents($laravel.'/shared/demo-logins.txt', 'SECRET-DO-NOT-PRINT');
        mkdir($home.'/www/app.reserveworx.com/public_html', 0777, true);
        file_put_contents($home.'/www/app.reserveworx.com/public_html/platform-admin.txt', 'SECRET-DO-NOT-PRINT');

        $ran = $this->lib($home, 'maintenance_report before');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('maintenance_release='.self::SHA_A.' directory find=3 vendor=2', $ran['output']);
        $this->assertStringContainsString('maintenance_previous=REAL DIRECTORY', $ran['output']);
        $this->assertStringContainsString('maintenance_current=symlink', $ran['output']);
        $this->assertStringContainsString('maintenance_find_laravel=', $ran['output']);
        $this->assertStringContainsString('maintenance_find_L_laravel=', $ran['output']);
        $this->assertStringContainsString('maintenance_secret_file=www/app.reserveworx.com/laravel/shared/demo-logins.txt under_web_root=no', $ran['output']);
        $this->assertStringContainsString('maintenance_secret_file=www/app.reserveworx.com/public_html/platform-admin.txt under_web_root=YES', $ran['output']);
        $this->assertStringNotContainsString('SECRET-DO-NOT-PRINT', $ran['output']);
    }

    public function test_maintenance_prune_moves_a_secret_file_out_of_the_web_root_without_reading_it(): void
    {
        $home = $this->home();
        $laravel = $this->laravel($home);
        mkdir($laravel.'/releases/'.self::SHA_A, 0777, true);
        symlink($laravel.'/releases/'.self::SHA_A, $laravel.'/current');
        mkdir($home.'/www/app.reserveworx.com/public_html', 0777, true);
        file_put_contents($home.'/www/app.reserveworx.com/public_html/demo-logins.txt', 'SECRET-DO-NOT-PRINT');

        $ran = $this->lib($home, 'maintenance_prune');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertFileDoesNotExist($home.'/www/app.reserveworx.com/public_html/demo-logins.txt');
        $this->assertFileExists($laravel.'/shared/demo-logins.txt');
        $this->assertStringContainsString('maintenance_secret_moved=demo-logins.txt', $ran['output']);
        $this->assertStringNotContainsString('SECRET-DO-NOT-PRINT', $ran['output']);
    }

    public function test_maintenance_is_a_mode_of_the_existing_deploy_workflow_that_never_deploys(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        $script = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));

        $this->assertStringContainsString('- maintenance', $workflow);
        $this->assertStringContainsString('RW_MAINTENANCE_PRUNE', $workflow);
        $this->assertSame([], glob(base_path('.github/workflows/*maint*')) ?: []);

        $maintenance = strpos($script, 'if [[ "$ACTION" == "maintenance" ]]; then');
        $this->assertNotFalse($maintenance);
        $this->assertLessThan(strpos($script, 'find_php'.PHP_EOL, $maintenance) ?: PHP_INT_MAX, $maintenance);
        $block = substr($script, $maintenance, 600);
        $this->assertStringNotContainsString('inode_guard', $block);
        $this->assertStringContainsString('exit 0', $block);
        foreach (['Install production dependencies', 'Pack one release archive', 'Upload release archive', 'Back up the application database'] as $step) {
            $this->assertMatchesRegularExpression('/- name: '.preg_quote($step, '/').'\n\s+(id: \w+\n\s+)?if: [^\n]*inputs\.action == \'deploy\'/', $workflow);
        }
    }

    public function test_at_most_two_database_dumps_are_kept_and_stale_client_files_are_removed(): void
    {
        $home = $this->home();
        $backups = $this->laravel($home).'/shared/backups';
        mkdir($backups, 0777, true);
        foreach ([5, 4, 3, 2, 1] as $age) {
            $file = $backups.'/2026010'.$age.'T000000Z.sql.gz';
            file_put_contents($file, 'x');
            touch($file, time() - $age * 3600);
        }
        $staleClient = $backups.'/.client.AbCdEf';
        $freshClient = $backups.'/.client.GhIjKl';
        file_put_contents($staleClient, 'x');
        file_put_contents($freshClient, 'x');
        touch($staleClient, time() - 7200);

        $ran = $this->lib($home, 'prune_db_dumps');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $left = glob($backups.'/*.sql.gz');
        sort($left);
        $this->assertSame([$backups.'/20260101T000000Z.sql.gz', $backups.'/20260102T000000Z.sql.gz'], $left);
        $this->assertFileDoesNotExist($staleClient);
        $this->assertFileExists($freshClient);

        $again = $this->lib($home, 'inode_cleanup');
        $this->assertCount(2, glob($backups.'/*.sql.gz'));
        $this->assertStringContainsString('db_dumps_kept=2', $again['output']);
    }

    public function test_log_settings_are_pinned_to_daily_seven_days_without_touching_other_lines(): void
    {
        $home = $this->home();
        $env = $this->laravel($home).'/shared/.env';
        file_put_contents($env, "APP_NAME=\"ReserveWorx\"\nLOG_CHANNEL=stack\nDB_PASSWORD=\"keep-me\"\nLOG_LEVEL=error");

        $ran = $this->lib($home, 'ensure_log_settings');

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringNotContainsString('keep-me', $ran['output']);
        $this->assertSame(
            "APP_NAME=\"ReserveWorx\"\nLOG_CHANNEL=daily\nDB_PASSWORD=\"keep-me\"\nLOG_LEVEL=error\nLOG_DAILY_DAYS=7\n",
            file_get_contents($env),
        );

        $this->lib($home, 'ensure_log_settings');
        $this->assertSame(1, substr_count((string) file_get_contents($env), 'LOG_DAILY_DAYS='));
        $this->assertSame([], glob($this->laravel($home).'/shared/.env-log.*'));
    }

    public function test_production_defaults_use_the_daily_channel_with_a_seven_day_limit(): void
    {
        $this->assertStringContainsString("'LOG_CHANNEL' => 'daily'", (string) file_get_contents(base_path('scripts/write-production-env.sh')));
        $this->assertStringContainsString("'LOG_DAILY_DAYS' => '7'", (string) file_get_contents(base_path('scripts/write-production-env.sh')));
        $this->assertSame(7, config('logging.channels.daily.days'));
        $script = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));
        $this->assertStringContainsString('log_retention_minutes=10080', $script);
    }

    public function test_the_archive_excludes_everything_a_server_release_must_not_carry(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
        preg_match_all("/--exclude '([^']*)'/", $workflow, $matches);
        $excludes = $matches[1];

        foreach (['.git', '.env', '.env.*', 'node_modules', 'tests', '.github', '.cursor', 'docs', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/pail'] as $required) {
            $this->assertContains($required, $excludes, $required.' must stay out of the release archive');
        }

        $root = sys_get_temp_dir().'/rw-excl-'.bin2hex(random_bytes(4));
        $archive = $root.'.tar.gz';
        $this->homes[] = $root;
        $this->homes[] = $archive;

        $keep = ['artisan', 'app/Models/User.php', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php', 'storage/app/public/.gitignore'];
        $drop = ['.git/HEAD', '.env', '.env.production', 'node_modules/x/index.js', 'tests/Feature/A.php', '.github/workflows/ci.yml', '.cursor/rules/a.mdc', 'docs/CHANGELOG.md', 'docs/changelog.d/note.md', 'storage/logs/laravel.log', 'storage/framework/cache/data/ab/entry', 'storage/framework/sessions/s1', 'storage/framework/views/v.php', 'storage/pail/p'];
        foreach (array_merge($keep, $drop) as $path) {
            @mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, 'x');
        }

        $command = 'tar -czf '.escapeshellarg($archive);
        foreach ($excludes as $exclude) {
            $command .= ' --exclude '.escapeshellarg($exclude);
        }
        $command .= ' -C '.escapeshellarg($root).' .';
        $this->assertSame(0, $this->sh($command)['exit']);

        $listed = $this->sh('tar -tzf '.escapeshellarg($archive))['output'];
        foreach ($keep as $path) {
            $this->assertStringContainsString('./'.$path, $listed);
        }
        foreach ($drop as $path) {
            $this->assertStringNotContainsString('./'.$path, $listed, $path.' leaked into the archive');
        }
    }
}
