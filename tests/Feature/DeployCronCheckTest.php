<?php

namespace Tests\Feature;

use App\Support\UpgradeGuide;
use Tests\TestCase;

class DeployCronCheckTest extends TestCase
{
    public function test_a_schedule_run_line_is_present_and_the_command_stays_hidden(): void
    {
        $canary = '/home/secret-canary/artisan schedule:run --no-interaction';
        $ran = $this->check("* * * * * /usr/local/php82/bin/php {$canary}\n# {$canary}\n");

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertSame("cron_check=present\n", $ran['output']);
        $this->assertStringNotContainsString('secret-canary', $ran['output']);
        $this->assertStringNotContainsString('php82', $ran['output']);
        $this->assertStringNotContainsString('schedule:run', $ran['output']);
    }

    public function test_a_comment_an_assignment_and_another_job_are_not_schedule_run(): void
    {
        $canary = 'whsec_canary_path_/opt/hidden/artisan';
        $text = "# * * * * * {$canary} schedule:run\n"
            ."FOO=schedule:run\n"
            ."15 4 * * * {$canary} php artisan queue:work\n"
            ."schedule:running\n"
            ."notschedule:run\n";
        $ran = $this->check($text);

        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertSame("cron_check=missing\n", $ran['output']);
        $this->assertStringNotContainsString('whsec_', $ran['output']);
        $this->assertStringNotContainsString('queue:work', $ran['output']);
        $this->assertStringNotContainsString('/opt/hidden', $ran['output']);
    }

    public function test_an_empty_crontab_and_a_missing_file_do_not_fail(): void
    {
        $empty = $this->check("\n\n");
        $this->assertSame(0, $empty['exit'], $empty['output']);
        $this->assertSame("cron_check=empty\n", $empty['output']);

        $missing = $this->check(null);
        $this->assertSame(0, $missing['exit'], $missing['output']);
        $this->assertSame("cron_check=unavailable\n", $missing['output']);
        $this->assertStringNotContainsString('missing-crontab', $missing['output']);
    }

    public function test_the_deploy_script_checks_cron_without_printing_the_command(): void
    {
        $script = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));

        $this->assertStringContainsString("echo \"deploy=complete\"\ncron_check\n", $script);
        $this->assertStringNotContainsString('cron_hint=', $script);
        $this->assertStringNotContainsString('cron_command_body=', $script);
        $this->assertStringNotContainsString('${php_bin} ${current}/artisan schedule:run', $script);

        $step = collect(app(UpgradeGuide::class)->steps())->firstWhere('title', 'Point cron at the current release');
        $this->assertIsArray($step);
        $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
        $this->assertStringContainsString($step['body'], $deploy);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function check(?string $contents): array
    {
        $home = sys_get_temp_dir().'/rw-cron-'.bin2hex(random_bytes(4));
        $domain = $home.'/www/app.reserveworx.com';
        mkdir($domain, 0777, true);
        $file = $contents === null ? $home.'/missing-crontab.txt' : $home.'/crontab.txt';

        if ($contents !== null) {
            file_put_contents($file, $contents);
        }

        $command = 'export HOME='.escapeshellarg($home)
            .' ACTION=deploy RW_DEPLOY_LIBRARY=1 RW_CRON_CHECK_FILE='.escapeshellarg($file)
            .'; source '.escapeshellarg(base_path('scripts/remote-deploy.sh'))
            .'; cron_check';

        try {
            return $this->runShell($command);
        } finally {
            $this->runShell('rm -rf '.escapeshellarg($home));
        }
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
}
