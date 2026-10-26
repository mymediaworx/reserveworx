<?php

namespace App\Console\Commands;

use App\Services\Platform\TenantOnboarding;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CreateTenant extends Command
{
    protected $signature = 'reserveworx:tenant:create {slug} {--name=} {--owner-email=} {--timezone=America/New_York} {--hostname=}';

    protected $description = 'Create (or confirm) a customer workspace, default location, owner and subdomain. Idempotent.';

    public function handle(TenantOnboarding $onboarding): int
    {
        $slug = (string) $this->argument('slug');
        $name = (string) ($this->option('name') ?: $slug);

        try {
            $result = $onboarding->apply($slug, $name, $this->option('owner-email') ?: null, (string) $this->option('timezone'), $this->option('hostname') ?: null);
        } catch (InvalidArgumentException $e) {
            $this->error('tenant='.$slug.' error='.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'tenant=%s hostname=%s created=%s owner_link_sent=%s',
            $result['tenant']->slug,
            $result['hostname'],
            $result['created'] === [] ? 'none' : implode(',', $result['created']),
            $result['link_sent'] ? 'yes' : 'no',
        ));
        if ($this->option('owner-email') === null || $this->option('owner-email') === '') {
            $this->warn('tenant='.$result['tenant']->slug.' owner=missing');
        }

        return self::SUCCESS;
    }
}
