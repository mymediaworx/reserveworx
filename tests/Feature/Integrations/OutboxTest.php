<?php

namespace Tests\Feature\Integrations;

use App\Enums\TenantStatus;
use App\Models\IntegrationOutbox;
use App\Models\NotificationHook;
use App\Services\Integrations\Outbox;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_events_stay_local_and_a_different_workspace_is_refused(): void
    {
        Http::fake();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $tenants = app(TenantManager::class);
        $outbox = app(Outbox::class);

        $tenants->set($alpha['tenant']);
        $outbox->enqueue($alpha['tenant']->id, 'booking.confirmed', 'booking.confirmed:1', [
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]);
        $outbox->enqueue($alpha['tenant']->id, 'booking.confirmed', 'booking.confirmed:1', [
            'tenant_id' => $alpha['tenant']->id,
        ]);
        $this->assertSame(1, IntegrationOutbox::query()->count());

        try {
            $outbox->enqueue($beta['tenant']->id, 'booking.confirmed', 'booking.confirmed:2', [
                'tenant_id' => $beta['tenant']->id,
            ]);
            $this->fail('A different workspace was queued.');
        } catch (TenantContextException $exception) {
            $this->assertSame('Refusing to queue an integration event for a different workspace.', $exception->getMessage());
        }

        $beta['tenant']->status = TenantStatus::Suspended;
        $beta['tenant']->save();
        $foreign = IntegrationOutbox::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'destination' => 'ghl',
            'kind' => 'booking.cancelled',
            'dedupe_key' => 'booking.cancelled:9',
            'payload' => ['tenant_id' => $beta['tenant']->id],
            'status' => 'queued',
            'attempts' => 0,
        ]);

        NotificationHook::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'kind' => 'booking.confirmed',
            'dedupe_key' => 'booking.confirmed:1',
            'payload' => ['tenant_id' => $alpha['tenant']->id],
        ]);
        $tenants->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        $tenants->set($alpha['tenant']);
        $hook = NotificationHook::query()->firstOrFail();
        $this->assertSame('skipped', $hook->delivery_status);
        $this->assertSame('No transactional email provider is configured.', $hook->delivery_error);
        $row = IntegrationOutbox::query()->where('dedupe_key', 'booking.confirmed:1')->firstOrFail();
        $this->assertSame(1, $row->attempts);
        $this->assertSame('queued', $row->status);
        $this->assertSame('GoHighLevel is not connected. The event stays queued.', $row->last_error);
        $tenants->forget();

        $untouched = IntegrationOutbox::withoutGlobalScope(TenantScope::class)->findOrFail($foreign->id);
        $this->assertSame(0, $untouched->attempts);
        $this->assertNull($untouched->last_error);

        foreach (['2026-06-16 12:01:00', '2026-06-16 12:06:00', '2026-06-16 12:21:00', '2026-06-16 13:21:00'] as $moment) {
            Carbon::setTestNow(Carbon::parse($moment, 'UTC'));
            $this->artisan('integrations:dispatch')->assertSuccessful();
        }

        $tenants->set($alpha['tenant']);
        $held = IntegrationOutbox::query()->where('dedupe_key', 'booking.confirmed:1')->firstOrFail();
        $this->assertSame('held', $held->status);
        $this->assertSame(5, $held->attempts);
        $tenants->forget();

        Http::assertNothingSent();
        Mail::assertNothingSent();

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/integrations/outbox'))
            ->assertOk()
            ->assertJsonPath('data.held', 1)
            ->assertJsonPath('data.events.0.kind', 'booking.confirmed')
            ->assertJsonMissing(['booking_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/admin/integrations'))
            ->assertOk()
            ->assertSee('GoHighLevel is not connected')
            ->assertSee('booking.confirmed')
            ->assertDontSee('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    }
}
