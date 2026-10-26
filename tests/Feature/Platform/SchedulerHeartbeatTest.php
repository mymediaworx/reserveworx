<?php

namespace Tests\Feature\Platform;

use App\Console\Commands\Smoke;
use App\Livewire\Platform\Tenants;
use App\Models\PlatformAdministrator;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_schedule_writes_a_heartbeat_every_minute_that_smoke_reads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'scheduler-heartbeat');
        $this->assertInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame('missing', app(SchedulerHeartbeat::class)->status());

        $event->run($this->app);

        $this->assertSame('2026-10-25T12:00:00Z', Cache::get(Smoke::SCHEDULE_LAST_RUN_KEY));
        $this->assertSame('ok', app(SchedulerHeartbeat::class)->status());

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:11:00', 'UTC'));
        $this->assertSame('stale', app(SchedulerHeartbeat::class)->status());
    }

    public function test_platform_admins_are_warned_until_the_scheduler_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin);

        Livewire::test(Tenants::class)
            ->assertSee('Scheduled jobs are not running.')
            ->assertSeeHtml('data-scheduler="missing"');

        app(SchedulerHeartbeat::class)->beat();

        Livewire::test(Tenants::class)->assertDontSee('Scheduled jobs are not running.');

        Carbon::setTestNow(Carbon::parse('2026-10-25 12:30:00', 'UTC'));

        Livewire::test(Tenants::class)
            ->assertSee('The scheduler last ran 30 minutes ago.')
            ->assertSeeHtml('data-scheduler="stale"');
    }
}
