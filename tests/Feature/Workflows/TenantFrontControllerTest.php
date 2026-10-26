<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

class TenantFrontControllerTest extends TestCase
{
    public function test_it_writes_only_into_existing_subdomain_folders(): void
    {
        $home = sys_get_temp_dir().'/rw-tenant-'.bin2hex(random_bytes(4));
        $yak = $home.'/www/yakshack.reserveworx.com/public_html';
        $main = $home.'/www/reserveworx.com/public_html';
        $app = $home.'/www/app.reserveworx.com';
        mkdir($yak, 0777, true);
        mkdir($main, 0777, true);
        mkdir($app.'/public_html', 0777, true);
        file_put_contents($main.'/index.html', 'main-site');
        file_put_contents($app.'/public_html/.htaccess', "RewriteEngine On\n");

        $script = base_path('scripts/remote-deploy.sh');
        $run = function (string $calls) use ($home, $script): array {
            $cmd = 'export HOME='.escapeshellarg($home).' ACTION=deploy RW_DEPLOY_LIBRARY=1; source '.escapeshellarg($script).'; '.$calls.' 2>&1';
            exec('bash -c '.escapeshellarg($cmd), $out, $exit);

            return ['exit' => $exit, 'output' => implode("\n", $out)];
        };

        $ran = $run('install_tenant_front_controller yakshack.reserveworx.com');
        $this->assertSame(0, $ran['exit'], $ran['output']);
        $this->assertStringContainsString('tenant_front_controller=written host=yakshack.reserveworx.com', $ran['output']);
        $this->assertStringContainsString("\$root = '".$app."/laravel/current';", (string) file_get_contents($yak.'/index.php'));
        $this->assertSame("RewriteEngine On\n", (string) file_get_contents($yak.'/.htaccess'));

        $missing = $run('install_tenant_front_controller ghost.reserveworx.com');
        $this->assertSame(0, $missing['exit']);
        $this->assertStringContainsString('tenant_docroot=missing host=ghost.reserveworx.com', $missing['output']);
        $this->assertDirectoryDoesNotExist($home.'/www/ghost.reserveworx.com');

        foreach (['reserveworx.com', 'app.reserveworx.com', '../x.reserveworx.com', 'evil.com'] as $bad) {
            $this->assertNotSame(0, $run('install_tenant_front_controller '.escapeshellarg($bad))['exit'], $bad);
        }
        $this->assertSame('main-site', (string) file_get_contents($main.'/index.html'));
        $this->assertFileDoesNotExist($main.'/index.php');

        exec('rm -rf '.escapeshellarg($home));
    }

    public function test_deploy_runs_tenant_sync_after_go_live(): void
    {
        $remote = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));
        $this->assertStringContainsString('sync_tenants || post_failed=1', $remote);
        $this->assertLessThan(strpos($remote, 'harbor_login_check || post_failed=1'), strpos($remote, 'sync_tenants || post_failed=1'));
    }
}
