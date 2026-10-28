<?php

namespace Tests\Feature\Notifications;

use App\Models\Asset;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Notifications\WaitlistJoinedMail;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaitlistJoinedDeliveryFailureTest extends TestCase
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
        parent::tearDown();
    }

    public function test_a_refused_waitlist_confirmation_is_recorded_once_and_the_party_stays_waiting(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $this->enableWaitlist($service);
        $token = $this->token($workspace);
        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('held-1', 'Held Guest', 'held@example.com'))
            ->assertRedirect();

        $full = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('full-1', 'Ada Kayak', 'ada@example.com'));
        $full->assertStatus(409)->assertSee('Join waitlist');
        $money = $this->moneyCounts();

        $sends = $this->refusingMailer();
        $joined = $this->post($this->action($full, '/waitlist'), $this->party('wait-1', 'Ada Kayak', 'ada@example.com'));
        $joined->assertOk()
            ->assertSee('You are on the waitlist')
            ->assertDontSee('We emailed')
            ->assertDontSee('smtp secret')
            ->assertDontSee('bea@example.com');

        app(TenantManager::class)->set($workspace['tenant']);
        $entry = WaitlistEntry::query()->where('idempotency_key', 'wait-1')->firstOrFail();
        $this->assertSame('waiting', $entry->status->value);
        $hook = NotificationHook::query()->where('dedupe_key', 'waitlist.joined:'.$entry->id)->firstOrFail();
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->delivery_error);
        $this->assertStringNotContainsString('smtp secret', (string) $hook->delivery_error);
        $this->assertStringNotContainsString('bea@example.com', (string) $hook->delivery_error);
        $this->assertSame(1, $sends->count);
        $this->assertFalse(app(WaitlistJoinedMail::class)->send($entry));
        $this->assertSame(1, $sends->count);
        $hook->refresh();
        $this->assertSame('failed', $hook->delivery_status);
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $hook->refresh();
        $entry->refresh();
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('waiting', $entry->status->value);
        $this->assertSame(1, $sends->count);
        $this->assertSame(0, NotificationHook::query()->where('tenant_id', '!=', $workspace['tenant']->id)->where('kind', 'waitlist.joined')->count());
        app(TenantManager::class)->forget();
        $this->assertSame($money, $this->moneyCounts());

        $other = $this->workspace('creek.test');
        $otherLocation = Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($other['tenant']);
        $otherService = Service::query()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $otherLocation->id,
            'name' => 'Creek boat',
            'kind' => 'rental',
            'max_participants' => 4,
            'waitlist_enabled' => true,
        ]);
        $otherEntry = WaitlistEntry::query()->create([
            'tenant_id' => $other['tenant']->id,
            'service_id' => $otherService->id,
            'location_id' => $otherLocation->id,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'customer_name' => 'Bea Creek',
            'customer_email' => 'bea@example.com',
            'status' => 'waiting',
        ]);
        config(['mail.default' => 'log']);
        Notification::swap(new ChannelManager($this->app));
        $this->assertFalse(app(WaitlistJoinedMail::class)->send($otherEntry));
        $otherHook = NotificationHook::query()->where('dedupe_key', 'waitlist.joined:'.$otherEntry->id)->firstOrFail();
        $this->assertSame('queued', $otherHook->delivery_status);
        $this->assertNull($otherHook->delivery_error);
        $this->assertSame('waiting', $otherEntry->fresh()?->status->value);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->fresh()?->delivery_error);
        $this->assertStringNotContainsString('bea@example.com', (string) $hook->fresh()?->delivery_error);
        app(TenantManager::class)->forget();
        $this->assertSame(1, $sends->count);
        $this->assertSame($money, $this->moneyCounts());
    }

    private function refusingMailer(): object
    {
        $sends = new class
        {
            public int $count = 0;
        };

        Mail::extend('refusing', function () use ($sends): TransportInterface {
            return new class($sends) implements TransportInterface
            {
                public function __construct(private object $sends) {}

                public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                {
                    $this->sends->count++;

                    throw new RuntimeException('smtp secret');
                }

                public function __toString(): string
                {
                    return 'refusing';
                }
            };
        });
        config([
            'mail.mailers.refusing.transport' => 'refusing',
            'mail.default' => 'refusing',
        ]);
        Notification::swap(new ChannelManager($this->app));

        return $sends;
    }

    /**
     * @return array<string, int>
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => DB::table('payments')->count(),
            'refunds' => DB::table('refunds')->count(),
            'platform_fees' => DB::table('platform_fees')->count(),
            'platform_fee_refunds' => DB::table('platform_fee_refunds')->count(),
            'price_rules' => DB::table('price_rules')->count(),
            'location_tax_rules' => DB::table('location_tax_rules')->count(),
            'pricing_snapshots' => DB::table('pricing_snapshots')->count(),
        ];
    }

    private function action(TestResponse $response, string $suffix): string
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
    private function party(string $key, string $name, string $email): array
    {
        return [
            'date' => '2026-06-15',
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
