<?php

namespace App\Console\Commands;

use App\Services\Platform\TenantOnboarding;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncTenants extends Command
{
    protected $signature = 'reserveworx:tenant:sync';

    protected $description = 'Apply config/tenants.php idempotently and print each tenant host for the deploy script.';

    public function handle(TenantOnboarding $onboarding): int
    {
        $failed = false;
        foreach ((array) config('tenants', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['slug'])) {
                continue;
            }
            $slug = (string) $entry['slug'];
            try {
                $result = $onboarding->apply(
                    $slug,
                    (string) ($entry['name'] ?? $slug),
                    isset($entry['owner_email']) ? (string) $entry['owner_email'] : null,
                    (string) ($entry['timezone'] ?? 'America/New_York'),
                );
            } catch (InvalidArgumentException $e) {
                $this->error('tenant='.$slug.' error='.$e->getMessage());
                $failed = true;

                continue;
            }
            $this->line(sprintf(
                'tenant=%s created=%s owner=%s owner_link_sent=%s',
                $result['tenant']->slug,
                $result['created'] === [] ? 'none' : implode(',', $result['created']),
                empty($entry['owner_email']) ? 'missing' : 'set',
                $result['link_sent'] ? 'yes' : 'no',
            ));
            $this->line('tenant_host='.$result['hostname']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
