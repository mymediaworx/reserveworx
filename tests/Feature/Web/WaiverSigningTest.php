<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Waivers;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\Participant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\CheckIn\WaiverGate;
use App\Services\Waivers\WaiverLinks;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaiverSigningTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    private const TEXT = 'I understand paddling has risks and I accept them for myself.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_publish_versions_and_only_settings_editors_can(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Waivers::class)
            ->assertSee('No waivers yet.')
            ->set('name', 'Liability waiver')
            ->set('body', self::TEXT)
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSee('Liability waiver is published.')
            ->set('name', 'Liability waiver')
            ->set('body', self::TEXT.' Updated.')
            ->call('publish')
            ->assertSee('Liability waiver version 2 is published.');

        $this->assertSame([1, 2], WaiverVersion::query()->orderBy('version')->pluck('version')->all());
        $this->assertSame(2, AuditLog::query()->where('action', 'waiver.published')->count());
        $this->assertSame(hash('sha256', self::TEXT.' Updated.'), app(WaiverGate::class)->current()->first()?->content_hash);

        $staff = $this->member($alpha['tenant']->id, 'staff');
        $this->actingAs($staff);
        Livewire::test(Waivers::class)
            ->assertSee('Liability waiver')
            ->assertDontSee('Publish a waiver')
            ->set('name', 'Sneaky')
            ->set('body', self::TEXT)
            ->call('publish')
            ->assertForbidden();

        $viewer = User::factory()->create();
        $role = Role::query()->create(['slug' => 'viewer', 'name' => 'Viewer']);
        $role->permissions()->sync([Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id]);
        Membership::factory()->create(['tenant_id' => $alpha['tenant']->id, 'user_id' => $viewer->id, 'role_id' => $role->id]);
        app(TenantManager::class)->forget();
        $this->actingAs($viewer)->get($this->tenantUrl('alpha.test', '/staff/waivers'))->assertForbidden();
    }

    public function test_a_guest_signs_from_the_confirmation_link_and_check_in_sees_it(): void
    {
        [$alpha, $booking] = $this->bookingWithWaiver();
        $this->assertTrue(app(WaiverLinks::class)->needsSigning($booking));
        $link = $this->link($booking);
        app(TenantManager::class)->forget();

        $this->get($link)
            ->assertOk()
            ->assertSee('Sign your waiver')
            ->assertSee(self::TEXT)
            ->assertSee('Person taking part');

        $this->post($link, ['agree' => ['booking' => '1'], 'signer_minor' => '1'])
            ->assertSessionHasErrors();
        $this->post($link, ['agree' => ['booking' => '1'], 'signer_name' => 'Ada Lovelace', 'signer_minor' => '1'])
            ->assertSessionHasErrors('guardian');

        $this->post($link, ['agree' => ['booking' => '1'], 'signer_name' => 'Ada Lovelace'], ['User-Agent' => 'PhoneBrowser/1.0'])
            ->assertRedirect();
        $this->get($link)->assertOk()->assertSee('All waivers for this booking are signed.');

        app(TenantManager::class)->set($alpha['tenant']);
        $signature = WaiverSignature::query()->sole();
        $this->assertSame('Ada Lovelace', $signature->participant_name);
        $this->assertSame(hash('sha256', self::TEXT), $signature->content_hash);
        $this->assertSame('PhoneBrowser/1.0', $signature->user_agent);
        $this->assertNotNull($signature->ip_address);
        $this->assertTrue(app(WaiverGate::class)->status($booking)->complete);
        $this->assertFalse(app(WaiverLinks::class)->needsSigning($booking));
    }

    public function test_named_people_each_sign_and_a_guardian_on_file_is_used(): void
    {
        [$alpha, $booking] = $this->bookingWithWaiver(3);
        Participant::query()->create(['tenant_id' => $alpha['tenant']->id, 'booking_id' => $booking->id, 'name' => 'Grace Hopper', 'is_minor' => false, 'payload_hash' => hash('sha256', 'grace')]);
        $kid = Participant::query()->create(['tenant_id' => $alpha['tenant']->id, 'booking_id' => $booking->id, 'name' => 'Kid Hopper', 'is_minor' => true, 'guardian_name' => 'Grace Hopper', 'payload_hash' => hash('sha256', 'kid')]);
        $teen = Participant::query()->create(['tenant_id' => $alpha['tenant']->id, 'booking_id' => $booking->id, 'name' => 'Teen Hopper', 'is_minor' => true, 'payload_hash' => hash('sha256', 'teen')]);
        $link = $this->link($booking);
        $ids = Participant::query()->where('booking_id', $booking->id)->pluck('id')->all();
        app(TenantManager::class)->forget();

        $page = $this->get($link)->assertOk()->assertSee('Grace Hopper')->assertSee('Signed by Grace Hopper as parent or guardian.');
        $page->assertSee('name="guardian['.$teen->id.']"', false);
        $page->assertDontSee('name="guardian['.$kid->id.']"', false);

        $agree = array_fill_keys($ids, '1');
        $this->post($link, ['agree' => $agree])->assertSessionHasErrors('guardian');
        $this->post($link, ['agree' => $agree, 'guardian' => [$teen->id => 'Alan Hopper']])->assertRedirect();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(3, WaiverSignature::query()->count());
        $this->assertSame('Alan Hopper', WaiverSignature::query()->where('participant_id', $teen->id)->sole()->guardian_name);
        $this->assertTrue(app(WaiverGate::class)->status($booking)->complete);
    }

    public function test_a_link_does_not_work_on_another_workspace_or_with_a_changed_signature(): void
    {
        [, $booking] = $this->bookingWithWaiver();
        $link = $this->link($booking);
        $this->workspace('beta.test');

        $this->get(str_replace('alpha.test', 'beta.test', $link))->assertNotFound();
        $this->get($link.'x')->assertForbidden();
        $this->post(str_replace('alpha.test', 'beta.test', $link), ['agree' => ['booking' => '1'], 'signer_name' => 'Mallory'])->assertNotFound();
        $this->assertSame(0, WaiverSignature::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: array<string, mixed>, 1: Booking}
     */
    private function bookingWithWaiver(int $party = 1): array
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        WaiverVersion::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Liability waiver',
            'version' => 1,
            'body' => self::TEXT,
            'content_hash' => hash('sha256', self::TEXT),
        ]);
        $allocator = app(Allocator::class);
        $start = CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC');
        $booking = $allocator->confirm($allocator->hold(new AllocationIntent($alpha['tenant']->id, $shop['service']->id, $start, $start->addHour(), $party, 'w1', 'Ada Lovelace', 'ada@example.com')));

        return [$alpha, $booking];
    }

    private function link(Booking $booking): string
    {
        URL::forceRootUrl('http://alpha.test');

        try {
            return app(WaiverLinks::class)->url($booking);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function member(string $tenantId, string $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->role($role)->create(['tenant_id' => $tenantId, 'user_id' => $user->id]);

        return $user;
    }
}
