<?php

namespace Tests\Feature\Workflows;

use Tests\TestCase;

class ExportToDigitalOceanTest extends TestCase
{
    public function test_the_export_is_a_dispatch_only_job_of_the_existing_deploy_workflow(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy.yml');

        $this->assertStringContainsString('- export-to-digitalocean', $workflow);
        $this->assertStringContainsString("github.event_name == 'workflow_dispatch' && inputs.action == 'export-to-digitalocean'", $workflow);
        $this->assertStringContainsString("inputs.action != 'export-to-digitalocean'", $workflow);
        $this->assertStringContainsString('scripts/import-dump-digitalocean.sh', $workflow);
        $this->assertStringContainsString('secrets.DO_SSH_KEY', $workflow);
        $this->assertStringContainsString('-o StrictHostKeyChecking=yes', $workflow);
        $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $workflow);
        $this->assertSame([], glob(dirname(__DIR__, 3).'/.github/workflows/*export*') ?: []);
    }

    public function test_the_import_script_never_prints_secrets_or_rows_and_removes_its_transfer_files(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3).'/scripts/import-dump-digitalocean.sh');

        $this->assertStringContainsString('gunzip -t', $script);
        $this->assertStringContainsString('EXPECT_BYTES', $script);
        $this->assertStringContainsString('trap cleanup EXIT', $script);
        $this->assertStringContainsString('rm -f "$dump" "$storage"', $script);
        $this->assertStringContainsString('TRUNCATE TABLE', $script);
        $this->assertStringContainsString('CREATE DATABASE|USE ', $script);
        $this->assertStringContainsString('--defaults-extra-file', $script);
        $this->assertStringContainsString('STRIPE_SECRET', $script);
        $this->assertStringNotContainsString('echo "$password', $script);
        $this->assertStringNotContainsString('SELECT *', $script);
    }
}
