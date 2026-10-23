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
            $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $contents);
            $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $contents);
        }

        $remote = (string) file_get_contents(base_path('scripts/remote-deploy.sh'));
        $this->assertStringContainsString('app.reserveworx.com', $remote);
        $this->assertStringNotContainsString('public_html/app.reserveworx.com', $remote);
        $this->assertStringContainsString('Successful purge', $remote);
        $this->assertStringContainsString('HTTP 202', $remote);
        $this->assertStringContainsString('2>/dev/null)" || continue', $remote);
        $this->assertStringContainsString("tr '[:upper:]' '[:lower:]'", $remote);

        $prepare = (string) file_get_contents(base_path('scripts/prepare-siteground-ssh.sh'));
        $this->assertStringContainsString('sg_key.pub', $prepare);
        $this->assertStringContainsString('IdentitiesOnly=yes', $prepare);
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
