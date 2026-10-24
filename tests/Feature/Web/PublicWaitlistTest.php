<?php

namespace Tests\Feature\Web;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistJoinedNotification;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PublicWaitlistTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        parent::tearDown();
    }

    public function test_a_full_slot_offers_the_waitlist_and_the_email_link_leaves_it(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        [$workspace, $service] = $this->rental('dock.test');
        $this->enableWaitlist($service);
        $token = $this->token($workspace);
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('held-1'))
            ->assertRedirect();

        $full = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('wait-1', 'Ada Kayak', 'ada@example.com'));
        $full->assertStatus(409)
            ->assertSee('Join waitlist')
            ->assertSee('Phone')
            ->assertSee('(optional)', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('That time is closed.');

        $joined = $this->post($this->action($full, '/waitlist'), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 2,
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'ada@example.com',
            'customer_phone' => '312-555-0100',
            'idempotency_key' => 'wait-1',
        ])->assertOk();
        $joined->assertSee('You are on the waitlist')
            ->assertSee('We emailed ada@example.com a link')
            ->assertSee('@media (max-width: 390px)', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $entry = WaitlistEntry::query()->where('idempotency_key', 'wait-1')->firstOrFail();
        $this->assertSame('waiting', $entry->status->value);
        $this->assertSame('312-555-0100', $entry->customer_phone);
        $this->assertSame(2, $entry->participants);
        $this->assertSame('ada@example.com', $entry->customer_email);
        $this->assertTrue(AuditLog::query()->where('action', 'waitlist.joined')->where('subject_id', $entry->id)->exists());
        $hook = NotificationHook::query()->where('dedupe_key', 'waitlist.joined:'.$entry->id)->firstOrFail();
        $this->assertSame('sent', $hook->delivery_status);
        app(TenantManager::class)->forget();

        $leaveUrl = null;
        Notification::assertSentOnDemand(
            WaitlistJoinedNotification::class,
            function (WaitlistJoinedNotification $notification, array $channels, object $notifiable) use (&$leaveUrl): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $this->assertSame('ada@example.com', $notifiable->routes['mail'] ?? null);
                $mail = $notification->toMail($notifiable);
                $html = (string) $mail->render();
                $this->assertSame('You are on the waitlist', $mail->subject);
                $this->assertStringContainsString('Leave the waitlist', $html);
                preg_match('#https?://[^\s<"]+/waitlist/[^\s<"]+#', $html, $matches);
                $this->assertNotEmpty($matches);
                $leaveUrl = html_entity_decode($matches[0]);

                return $channels === ['mail'];
            },
        );
        $this->assertIsString($leaveUrl);

        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'/waitlist'), $this->party('wait-1', 'Ada Kayak', 'ada@example.com'))
            ->assertOk()
            ->assertSee('This request was already on the waitlist.');
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'/waitlist'), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 9,
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'too-big',
        ])->assertStatus(422)->assertSee('That party is larger than this service allows.');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, WaitlistEntry::query()->count());
        $this->assertSame(2, WaitlistEntry::query()->firstOrFail()->participants);
        app(TenantManager::class)->forget();
        Notification::assertSentOnDemandTimes(WaitlistJoinedNotification::class, 1);

        $page = $this->get($leaveUrl)->assertOk()->assertSee('Leave the waitlist')->assertSee('@media (max-width: 390px)', false);
        preg_match('/name="_token" value="([^"]+)"/', (string) $page->getContent(), $tokenMatch);
        $this->assertNotEmpty($tokenMatch);
        $this->post($leaveUrl, ['_token' => $tokenMatch[1]])
            ->assertOk()
            ->assertSee('You left the waitlist');
        $this->get($leaveUrl)->assertOk()->assertSee('You left the waitlist');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('cancelled', $entry->fresh()?->status->value);
        $this->assertSame(1, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $entry->id)->count());
        app(TenantManager::class)->forget();

        $this->get($leaveUrl.'x')->assertForbidden();
    }

    public function test_a_closed_time_or_a_service_without_the_waitlist_does_not_offer_it(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $token = $this->token($workspace);
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('held-off'))
            ->assertRedirect();
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('full-off'))
            ->assertStatus(409)
            ->assertSee('That time is no longer available.')
            ->assertDontSee('Join waitlist');
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'/waitlist'), $this->party('full-off'))
            ->assertNotFound();

        $this->enableWaitlist($service);
        app(TenantManager::class)->set($workspace['tenant']);
        LocationClosure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'reason' => 'Holiday',
            'all_day' => true,
            'recurs_yearly' => false,
            'starts_on' => '2026-06-16',
            'ends_on' => '2026-06-16',
        ]);
        app(TenantManager::class)->forget();

        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('closed-1', 'Ada Kayak', 'ada@example.com', '2026-06-16'))
            ->assertStatus(409)
            ->assertSee('That time is closed.')
            ->assertDontSee('Join waitlist');

        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'/waitlist'), $this->party('open-1', 'Ada Kayak', 'ada@example.com', '2026-06-17'))
            ->assertStatus(409)
            ->assertSee('That time is open.');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, WaitlistEntry::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_array_mailer_or_a_turned_off_preference_still_keeps_the_entry(): void
    {
        Notification::fake();
        [$workspace, $service] = $this->rental('dock.test');
        $this->enableWaitlist($service);
        $token = $this->token($workspace);
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('held-array'))
            ->assertRedirect();

        $this->post($this->action($this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('array-1')), '/waitlist'), $this->party('array-1'))
            ->assertOk()
            ->assertSee('You are on the waitlist')
            ->assertDontSee('We emailed');
        Notification::assertNothingSent();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('queued', NotificationHook::query()->where('kind', 'waitlist.joined')->firstOrFail()->delivery_status);
        app(TenantManager::class)->forget();

        config(['mail.default' => 'smtp']);
        app(TenantManager::class)->set($workspace['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['waitlist.joined' => false];
        $setting->save();
        app(TenantManager::class)->forget();
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('held-quiet', 'Held', 'held@example.com', '2026-06-16'))
            ->assertRedirect();
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id.'/waitlist'), $this->party('quiet-1', 'Bea', 'bea@example.com', '2026-06-16'))
            ->assertOk()
            ->assertDontSee('We emailed');
        Notification::assertNothingSent();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('waiting', WaitlistEntry::query()->where('idempotency_key', 'quiet-1')->firstOrFail()->status->value);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  TestResponse  $response
     */
    private function action($response, string $suffix): string
    {
        preg_match('#action="([^"]+'.preg_quote($suffix, '#').')"#', (string) $response->getContent(), $matches);
        $this->assertNotEmpty($matches);

        return html_entity_decode($matches[1]);
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function token(array $workspace): string
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        app(TenantManager::class)->forget();

        return $token;
    }

    private function enableWaitlist(Service $service): void
    {
        app(TenantManager::class)->set($service->tenant);
        $service->waitlist_enabled = true;
        $service->save();
        app(TenantManager::class)->forget();
    }

    /**
     * @return array<string, string|int>
     */
    private function party(string $key, string $name = 'Ada', string $email = 'ada@example.com', string $date = '2026-06-15'): array
    {
        return [
            'date' => $date,
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => $name,
            'customer_email' => $email,
            'idempotency_key' => $key,
        ];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
            'max_participants' => 4,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Launch',
            'kind' => 'base',
            'amount_cents' => 2500,
            'window' => 'always',
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }
}
