<?php

namespace Tests\Feature\Operations;

use App\Enums\TenantStatus;
use App\Models\IntegrationOutbox;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\Tenant;
use App\Support\SharedHostBudget;
use App\Support\TenantSweep;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class SharedHostWindowTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_sweep_stops_at_the_window_and_continues_in_id_order(): void
    {
        $first = $this->workspace('first.test');
        $second = $this->workspace('second.test', 'owner', ['status' => TenantStatus::Onboarding]);
        $third = $this->workspace('third.test');
        $suspended = $this->workspace('suspended.test', 'owner', ['status' => TenantStatus::Suspended]);
        $terminated = $this->workspace('terminated.test', 'owner', ['status' => TenantStatus::Terminated]);

        $included = collect([$first['tenant'], $second['tenant'], $third['tenant']])
            ->sortBy('id')
            ->values();

        foreach ([$included[0], $included[1], $included[2], $suspended['tenant'], $terminated['tenant']] as $tenant) {
            $this->queueOutbox($tenant);
        }

        $this->bindOneVisitBudget();

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=1')
            ->expectsOutput('sweep_deferred=yes')
            ->assertSuccessful();

        $this->assertSame(1, $this->attempts($included[0]->id));
        $this->assertSame(0, $this->attempts($included[1]->id));
        $this->assertSame(0, $this->attempts($included[2]->id));
        $this->assertSame(0, $this->attempts($suspended['tenant']->id));
        $this->assertSame(0, $this->attempts($terminated['tenant']->id));
        $this->assertSame($included[0]->id, Cache::get('shared-host-sweep:integrations'));

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=1')
            ->expectsOutput('sweep_deferred=yes')
            ->assertSuccessful();

        $this->assertSame(1, $this->attempts($included[0]->id));
        $this->assertSame(1, $this->attempts($included[1]->id));
        $this->assertSame(0, $this->attempts($included[2]->id));
        $this->assertSame($included[1]->id, Cache::get('shared-host-sweep:integrations'));

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=1')
            ->expectsOutput('sweep_deferred=yes')
            ->assertSuccessful();

        $this->assertSame(1, $this->attempts($included[2]->id));
        $this->assertSame($included[2]->id, Cache::get('shared-host-sweep:integrations'));

        IntegrationOutbox::withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $included->pluck('id'))
            ->update(['available_at' => now()->subMinute()]);

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=1')
            ->expectsOutput('sweep_deferred=yes')
            ->assertSuccessful();

        $this->assertSame(2, $this->attempts($included[0]->id));
        $this->assertSame(1, $this->attempts($included[1]->id));
        $this->assertSame(1, $this->attempts($included[2]->id));
        $this->assertSame(0, $this->attempts($suspended['tenant']->id));
        $this->assertSame(0, $this->attempts($terminated['tenant']->id));
        $this->assertSame($included[0]->id, Cache::get('shared-host-sweep:integrations'));
        $this->assertNoMoneyMoved();

        $rows = IntegrationOutbox::withoutGlobalScope(TenantScope::class)->get();
        $this->assertCount(5, $rows);

        foreach ($rows as $row) {
            $this->assertSame('stay', $row->payload['marker'] ?? null);
        }
    }

    public function test_a_pass_that_fits_clears_the_cursor_and_visits_everyone_again(): void
    {
        $workspaces = [
            $this->workspace('alpha.test'),
            $this->workspace('beta.test', 'owner', ['status' => TenantStatus::Onboarding]),
            $this->workspace('gamma.test'),
        ];
        $suspended = $this->workspace('paused.test', 'owner', ['status' => TenantStatus::Suspended]);

        foreach ([...$workspaces, $suspended] as $workspace) {
            $this->queueOutbox($workspace['tenant']);
        }

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=3')
            ->expectsOutput('sweep_deferred=no')
            ->assertSuccessful();

        $this->assertNull(Cache::get('shared-host-sweep:integrations'));
        $this->assertSame(0, $this->attempts($suspended['tenant']->id));

        foreach ($workspaces as $workspace) {
            $this->assertSame(1, $this->attempts($workspace['tenant']->id));
        }

        $this->artisan('integrations:dispatch')
            ->expectsOutput('sweep_visited=3')
            ->expectsOutput('sweep_deferred=no')
            ->assertSuccessful();

        foreach ($workspaces as $workspace) {
            $this->assertSame(1, $this->attempts($workspace['tenant']->id));
        }

        $this->assertNull(Cache::get('shared-host-sweep:integrations'));
        $this->assertNoMoneyMoved();
    }

    public function test_an_unfinished_workspace_is_visited_again(): void
    {
        $first = $this->workspace('retry-a.test')['tenant'];
        $second = $this->workspace('retry-b.test')['tenant'];
        $lowest = $first->id < $second->id ? $first : $second;
        $failed = false;

        $this->app->bind(SharedHostBudget::class, fn (): SharedHostBudget => new SharedHostBudget(20, 0.0, fn (): float => 0.0));

        $result = app(TenantSweep::class)->each('retry', function (Tenant $tenant) use ($lowest, &$failed): bool {
            if ($tenant->id === $lowest->id && ! $failed) {
                $failed = true;

                return false;
            }

            return true;
        });

        $this->assertSame(0, $result->visited);
        $this->assertTrue($result->deferred);
        $this->assertNull(Cache::get('shared-host-sweep:retry'));

        $again = app(TenantSweep::class)->each('retry', fn (): bool => true);

        $this->assertSame(2, $again->visited);
        $this->assertFalse($again->deferred);
        $this->assertNull(Cache::get('shared-host-sweep:retry'));
    }

    public function test_limits_pass_inside_the_window_and_fail_outside_it(): void
    {
        config(['app.key' => 'base64:'.base64_encode('limits-secret-canary-value')]);

        $this->artisan('reserveworx:limits')
            ->expectsOutput('window_seconds=20')
            ->expectsOutput('configured_queue_work_max_time=20')
            ->expectsOutput('queue_worker=bounded')
            ->expectsOutput('jobs_within_window=yes')
            ->expectsOutput('schedule_overlap=yes')
            ->expectsOutput('within_limit=yes')
            ->doesntExpectOutputToContain('limits-secret-canary')
            ->assertSuccessful();

        config(['reserveworx.queue_work_max_time' => 90]);

        $this->artisan('reserveworx:limits')
            ->expectsOutput('window_seconds=20')
            ->expectsOutput('configured_queue_work_max_time=90')
            ->expectsOutput('queue_worker=bounded')
            ->expectsOutput('jobs_within_window=yes')
            ->expectsOutput('schedule_overlap=yes')
            ->expectsOutput('within_limit=no')
            ->assertFailed();
    }

    public function test_the_window_is_clamped_and_shared(): void
    {
        config(['reserveworx.queue_work_max_time' => 90]);
        $this->assertSame(20, SharedHostBudget::seconds());

        config(['reserveworx.queue_work_max_time' => 3]);
        $this->assertSame(10, SharedHostBudget::seconds());

        config(['reserveworx.queue_work_max_time' => 15]);
        $this->assertSame(15, SharedHostBudget::seconds());

        $open = new SharedHostBudget(20, 100.0, fn (): float => 119.9);
        $closed = new SharedHostBudget(20, 100.0, fn (): float => 120.0);
        $this->assertFalse($open->expired());
        $this->assertTrue($closed->expired());

        config(['reserveworx.queue_work_max_time' => 20]);
        Cache::forget('shared-host-window');
        SharedHostBudget::start();
        $anchor = Cache::get('shared-host-window');
        SharedHostBudget::start();

        $this->assertSame($anchor, Cache::get('shared-host-window'));
    }

    private function bindOneVisitBudget(): void
    {
        $this->app->bind(SharedHostBudget::class, function (): SharedHostBudget {
            $reads = 0;

            return new SharedHostBudget(20, 0.0, function () use (&$reads): float {
                $reads++;

                return $reads === 1 ? 0.0 : 20.0;
            });
        });
    }

    private function queueOutbox(Tenant $tenant): void
    {
        IntegrationOutbox::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'destination' => 'ghl',
            'kind' => 'booking.changed',
            'dedupe_key' => 'stay:'.$tenant->id,
            'payload' => ['marker' => 'stay'],
            'status' => 'queued',
            'attempts' => 0,
            'available_at' => null,
        ]);
    }

    private function attempts(string $tenantId): int
    {
        return (int) IntegrationOutbox::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->value('attempts');
    }

    private function assertNoMoneyMoved(): void
    {
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
    }
}
