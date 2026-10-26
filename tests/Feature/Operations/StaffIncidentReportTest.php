<?php

namespace Tests\Feature\Operations;

use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\IncidentStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\FileIncident;
use App\Livewire\Staff\Incidents;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Incident;
use App\Models\IncidentPhoto;
use App\Models\Location;
use App\Models\MaintenanceWindow;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\ResourceCategory;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\Allocator;
use App\Services\CheckIn\BalanceDue;
use App\Services\Customers\CustomerReceipts;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffIncidentReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_damage_amount_is_added_to_the_customer_total(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $open = $this->openCheckout($alpha['tenant'], $north, 'Damage Guest', 'Scuffed skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $open['booking'], withLateFee: true);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('Damage report')
            ->assertSee('File report')
            ->assertSee('data-fixed-asset="'.$open['asset']->id.'"', false);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$open['booking']->id))
            ->assertOk()
            ->assertSee('Damage report')
            ->assertSee('File report')
            ->assertSee('Choose');

        $this->useTenant($alpha['tenant']);
        Livewire::test(FileIncident::class, [
            'bookingId' => $open['booking']->id,
            'fixedAssetId' => $open['asset']->id,
        ])
            ->set('severity', 'moderate')
            ->set('notes', 'Bow scraped on the piling beside the dock.')
            ->set('damageAmount', '25.555')
            ->call('file')
            ->assertHasErrors(['damageAmount']);
        $this->assertSame(0, Incident::query()->count());

        Livewire::test(FileIncident::class, [
            'bookingId' => $open['booking']->id,
            'fixedAssetId' => $open['asset']->id,
        ])
            ->set('severity', 'moderate')
            ->set('notes', 'Bow scraped on the piling beside the dock.')
            ->set('damageAmount', '25.50')
            ->set('photos', [UploadedFile::fake()->image('bow.jpg', 24, 24)])
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.')
            ->assertSee('$25.50 was added to the customer total.');

        $this->useTenant($alpha['tenant']);
        $incident = Incident::query()->with('photos')->sole();
        $this->assertSame($open['asset']->id, $incident->asset_id);
        $this->assertSame($open['booking']->id, $incident->booking_id);
        $this->assertSame('moderate', $incident->severity?->value);
        $this->assertSame('open', $incident->status->value);
        $this->assertSame(2_550, $incident->damage_cents);
        $this->assertSame('Bow scraped on the piling beside the dock.', $incident->notes);
        $this->assertSame($alpha['user']->id, $incident->reported_by);
        $this->assertCount(1, $incident->photos);
        $photo = $incident->photos->first();
        $this->assertInstanceOf(IncidentPhoto::class, $photo);
        $this->assertSame('image/jpeg', $photo->mime);
        $this->assertTrue(str_starts_with($photo->path, 'incidents/'.$alpha['tenant']->id.'/'.$incident->id.'/'));
        Storage::disk('local')->assertExists($photo->path);

        $snapshot = PricingSnapshot::query()->where('booking_id', $open['booking']->id)->firstOrFail();
        $this->assertSame(14_650, $snapshot->payload['customer_total_cents']);
        $this->assertSame(2_550, $snapshot->payload['damage_cents']);
        $this->assertSame(2_550, $snapshot->payload['damage_lines'][0]['amount_cents']);
        $this->assertSame($incident->id, $snapshot->payload['damage_lines'][0]['incident_id']);
        $this->assertSame(1_000, $snapshot->payload['late_fees_cents']);
        $this->assertSame(10_000, $snapshot->payload['basis_cents']);
        $this->assertSame(300, $snapshot->payload['fee_cents']);
        $this->assertSame(3_550, app(BalanceDue::class)->forBooking($open['booking']->refresh())['cents']);
        $this->assertSame(1, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertNull(MaintenanceWindow::query()->first());

        $receipt = app(CustomerReceipts::class)->forBooking($open['booking']);
        $this->assertSame(2_550, $receipt['damage_cents']);
        $this->assertSame(1_000, $receipt['late_fee_cents']);
        $this->assertSame(14_650, $receipt['total_cents']);
        $this->assertIsString($receipt['html']);
        $this->assertStringContainsString('Damage', $receipt['html']);
        $this->assertStringContainsString('$25.50', $receipt['html']);
        $this->assertStringContainsString('Late fee', $receipt['html']);

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->where('action', 'incident.reported')
            ->firstOrFail();
        $this->assertSame(2_550, $audit->properties['damage_cents']);
        $this->assertSame(1, $audit->properties['photo_count']);
        $this->assertStringNotContainsString('piling', (string) json_encode($audit->properties));

        app(TenantManager::class)->forget();
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/incidents'))
            ->assertOk()
            ->assertSee('Bow scraped on the piling beside the dock.')
            ->assertSee('data-incident="'.$incident->id.'"', false)
            ->assertSee('data-severity="moderate"', false)
            ->assertSee('data-damage="2550"', false)
            ->assertSee('Mark resolved');
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$open['booking']->id))
            ->assertOk()
            ->assertSee('data-damage="2550"', false)
            ->assertSee('Damage $25.50 is included in the total.');
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents/'.$incident->id.'/photos/'.$photo->id))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_a_blank_amount_and_a_bad_photo_do_not_change_the_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $priced = $this->openCheckout($alpha['tenant'], $north, 'Blank Guest', 'Clean skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $unpriced = $this->openCheckout($alpha['tenant'], $north, 'Unpriced Guest', 'Plain skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $priced['booking']);
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        $photos = [];

        for ($index = 0; $index < 7; $index++) {
            $photos[] = UploadedFile::fake()->image('extra-'.$index.'.jpg', 8, 8);
        }

        Livewire::test(FileIncident::class, [
            'bookingId' => $priced['booking']->id,
            'fixedAssetId' => $priced['asset']->id,
        ])
            ->set('severity', 'minor')
            ->set('notes', 'A light scuff on the rail.')
            ->set('photos', [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->call('file')
            ->assertHasErrors(['photos.0']);

        Livewire::test(FileIncident::class, [
            'bookingId' => $priced['booking']->id,
            'fixedAssetId' => $priced['asset']->id,
        ])
            ->set('severity', 'minor')
            ->set('notes', 'A light scuff on the rail.')
            ->set('photos', $photos)
            ->call('file')
            ->assertHasErrors(['photos']);

        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(11_100, $this->total($priced['booking']));

        Livewire::test(FileIncident::class, [
            'bookingId' => $priced['booking']->id,
            'fixedAssetId' => $priced['asset']->id,
        ])
            ->set('severity', 'minor')
            ->set('notes', 'A light scuff on the rail.')
            ->set('damageAmount', '0.00')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.');

        $incident = Incident::query()->sole();
        $this->assertNull($incident->damage_cents);
        $this->assertSame(11_100, $this->total($priced['booking']));
        $this->assertSame(0, app(BalanceDue::class)->forBooking($priced['booking']->refresh())['cents']);

        Livewire::test(FileIncident::class, [
            'bookingId' => $unpriced['booking']->id,
            'fixedAssetId' => $unpriced['asset']->id,
        ])
            ->set('severity', 'moderate')
            ->set('notes', 'Needs a price before the amount can be added.')
            ->set('damageAmount', '10.00')
            ->call('file')
            ->assertHasErrors(['damageAmount']);

        $this->assertSame(1, Incident::query()->count());
        $this->assertNull(PricingSnapshot::query()->where('booking_id', $unpriced['booking']->id)->first());
    }

    public function test_reports_can_be_listed_filtered_and_resolved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $northOpen = $this->openCheckout($alpha['tenant'], $north, 'North Guest', 'North skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $southOpen = $this->openCheckout($alpha['tenant'], $south, 'South Guest', 'South skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $betaOpen = $this->openCheckout($beta['tenant'], $betaDock, 'Beta Guest', 'Beta skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $northOpen['booking']);
        $this->useTenant($alpha['tenant']);
        $minor = $this->storedIncident($northOpen, 'minor', 'North bow scrape', 500);
        $serious = $this->storedIncident($northOpen, 'serious', 'North hull crack', null, IncidentStatus::Resolved);
        $southReport = $this->storedIncident($southOpen, 'moderate', 'South secret scrape');
        $this->useTenant($beta['tenant']);
        $this->storedIncident($betaOpen, 'minor', 'Beta private scrape');
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/incidents'))
            ->assertOk()
            ->assertSee('North bow scrape')
            ->assertSee('South secret scrape')
            ->assertDontSee('North hull crack')
            ->assertDontSee('Beta private scrape')
            ->assertSee('max-width: 390px', false);
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents?status=all&severity=serious'))
            ->assertOk()
            ->assertSee('North hull crack')
            ->assertDontSee('North bow scrape')
            ->assertDontSee('South secret scrape');
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents?status=all&location='.$north->id))
            ->assertOk()
            ->assertSee('North bow scrape')
            ->assertSee('North hull crack')
            ->assertDontSee('South secret scrape');
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents?status=nope'))->assertNotFound();

        $this->useTenant($alpha['tenant']);
        Livewire::test(Incidents::class)->call('resolve', $minor->id)->assertSee('Marked resolved.');
        $minor->refresh();
        $this->assertSame('resolved', $minor->status->value);
        $this->assertSame($alpha['user']->id, $minor->resolved_by);
        $this->assertNotNull($minor->resolved_at);
        $this->assertSame(11_100, $this->total($northOpen['booking']));
        Livewire::test(Incidents::class)->call('resolve', $minor->id)->assertSee('Already resolved.');

        app(TenantManager::class)->forget();
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/incidents'))
            ->assertOk()
            ->assertDontSee('North bow scrape')
            ->assertSee('South secret scrape');
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents?status=resolved'))
            ->assertOk()
            ->assertSee('North bow scrape')
            ->assertSee('North hull crack')
            ->assertDontSee('South secret scrape');

        $desk = $this->member($alpha['tenant'], 'staff');
        $frontDesk = Role::query()->create(['slug' => 'front-desk', 'name' => 'Front desk']);
        $frontDesk->permissions()->sync([Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id]);
        $desk['membership']->update(['role_id' => $frontDesk->id]);
        app(TenantManager::class)->forget();
        $this->actingAs($desk['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/incidents?status=all'))
            ->assertOk()
            ->assertDontSee('Mark resolved');
        $this->useTenant($alpha['tenant']);
        Livewire::test(Incidents::class)->call('resolve', $southReport->id)->assertForbidden();
        Livewire::test(FileIncident::class, [
            'bookingId' => $southOpen['booking']->id,
            'fixedAssetId' => $southOpen['asset']->id,
        ])->assertForbidden();

        $limited = $this->member($alpha['tenant'], 'staff');
        $this->limit($limited['membership'], $north->id);
        $this->useTenant($alpha['tenant']);
        $southPhoto = IncidentPhoto::query()->create([
            'tenant_id' => $southReport->tenant_id,
            'incident_id' => $southReport->id,
            'path' => 'incidents/'.$southReport->tenant_id.'/'.$southReport->id.'/secret.jpg',
            'mime' => 'image/jpeg',
        ]);
        app(TenantManager::class)->forget();
        $this->actingAs($limited['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/incidents?status=all'))
            ->assertOk()
            ->assertSee('North bow scrape')
            ->assertDontSee('South secret scrape')
            ->assertDontSee('Beta private scrape');
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents?location='.$south->id))->assertNotFound();
        $this->useTenant($alpha['tenant']);
        Livewire::test(Incidents::class)->call('resolve', $southReport->id)->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents/'.$southReport->id.'/photos/'.$southPhoto->id))->assertNotFound();
    }

    public function test_a_serious_report_puts_the_asset_on_the_maintenance_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->location($alpha['tenant'], 'North dock');
        $open = $this->openCheckout($alpha['tenant'], $north, 'Return Guest', 'Cracked skiff', '2026-07-01 14:00:00', '2026-07-01 16:00:00');
        $started = $this->booked($alpha['tenant'], $north, $open['asset'], 'Started Guest', '2026-07-01 08:00:00', '2026-07-02 12:00:00');
        $overlap = $this->booked($alpha['tenant'], $north, $open['asset'], 'Future Guest', '2026-07-02 14:00:00', '2026-07-02 15:00:00');
        $clear = $this->booked($alpha['tenant'], $north, $open['asset'], 'Later Guest', '2026-07-03 10:00:00', '2026-07-03 11:00:00');
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        Livewire::test(FileIncident::class, ['bookingId' => $open['booking']->id])
            ->set('assetId', $open['asset']->id)
            ->set('severity', 'moderate')
            ->set('blockAsset', true)
            ->set('notes', 'A moderate scratch that stays in service.')
            ->call('file')
            ->assertHasNoErrors();
        $this->assertSame(0, MaintenanceWindow::query()->count());
        $this->assertNull($overlap['booking']->refresh()->attention_reason);

        Livewire::test(FileIncident::class, ['bookingId' => $open['booking']->id])
            ->set('assetId', $open['asset']->id)
            ->set('severity', 'serious')
            ->set('blockAsset', true)
            ->set('blockStarts', '2026-07-02T09:00')
            ->set('blockEnds', '2026-07-02T18:00')
            ->set('blockReason', 'Hull repair after the scrape')
            ->set('notes', 'The hull cracked on the way in.')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('1 future booking is flagged. Nothing was cancelled.');

        $window = MaintenanceWindow::query()->sole();
        $this->assertSame('maintenance', $window->kind->value);
        $this->assertSame($open['asset']->id, $window->asset_id);
        $this->assertSame('Hull repair after the scrape', $window->reason);
        $this->assertSame('2026-07-02 09:00:00', $window->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-02 18:00:00', $window->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('active', $open['asset']->refresh()->status->value);
        $this->assertSame(BookingStatus::Confirmed, $overlap['booking']->refresh()->status);
        $this->assertSame('Hull repair after the scrape', $overlap['booking']->attention_reason);
        $this->assertNull($started['booking']->refresh()->attention_reason);
        $this->assertNull($clear['booking']->refresh()->attention_reason);
        $this->assertSame(BookingStatus::Confirmed, $started['booking']->status);
        $this->assertSame(BookingStatus::Confirmed, $clear['booking']->status);
        $this->assertSame(4, Allocation::query()->count());

        $serious = Incident::query()->where('severity', 'serious')->sole();
        $this->assertSame($window->id, $serious->maintenance_window_id);

        app(TenantManager::class)->forget();
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/maintenance'))
            ->assertOk()
            ->assertSee('Hull repair after the scrape')
            ->assertSee('Cracked skiff')
            ->assertSee('Flagged')
            ->assertSee('data-affected-booking="'.$overlap['booking']->id.'"', false)
            ->assertDontSee('data-affected-booking="'.$started['booking']->id.'"', false)
            ->assertDontSee('data-affected-booking="'.$clear['booking']->id.'"', false);
    }

    public function test_a_report_cannot_use_another_workspaces_boat(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $open = $this->openCheckout($alpha['tenant'], $north, 'Alpha Guest', 'Alpha skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $other = $this->openCheckout($beta['tenant'], $betaDock, 'Beta Guest', 'Beta skiff', '2026-06-16 16:00:00', '2026-06-16 18:00:00');
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        Livewire::test(FileIncident::class, ['bookingId' => $other['booking']->id])->assertNotFound();
        Livewire::test(FileIncident::class, ['bookingId' => $open['booking']->id])
            ->set('assetId', $other['asset']->id)
            ->set('severity', 'minor')
            ->set('notes', 'This boat is not on the booking.')
            ->call('file')
            ->assertHasErrors(['assetId']);
        $this->assertSame(0, Incident::query()->count());

        $this->useTenant($beta['tenant']);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_a_pool_allocation_can_be_reported_before_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $fleet = $this->poolFleet($alpha['tenant'], $north, $south, assigned: true);
        $plain = $this->harborSingleKayak($alpha['tenant'], $north, 'Harbor Guest');
        $empty = $this->bareBooking($alpha['tenant'], $north, 'Empty Guest');
        $foreign = Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaDock->id,
            'name' => 'Beta kayak',
            'status' => ResourceStatus::Active,
        ]);
        $this->actingAs($alpha['user']);
        app(TenantManager::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$fleet['booking']->id))
            ->assertOk()
            ->assertSee('Damage report')
            ->assertSee('Single kayak × 1')
            ->assertSee('Able kayak')
            ->assertSee('Baker kayak')
            ->assertSee('Zebra skiff')
            ->assertDontSee('This report covers')
            ->assertDontSee('No boat is assigned to this booking yet.')
            ->assertDontSee('Shop kayak')
            ->assertDontSee('Tandem one')
            ->assertDontSee('South kayak')
            ->assertDontSee('Beta kayak');
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$plain['booking']->id))
            ->assertOk()
            ->assertSee('Damage report')
            ->assertSee('File report')
            ->assertSee('This report covers')
            ->assertSee('data-gear-type="'.$plain['pool']->id.'"', false)
            ->assertSee('Single kayak × 1')
            ->assertDontSee('Sam (guide)')
            ->assertDontSee('wire:model.live="assetId"', false)
            ->assertDontSee('No boat is assigned to this booking yet.');
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$empty->id))
            ->assertOk()
            ->assertSee('No boat is assigned to this booking yet.')
            ->assertDontSee('File report');

        $this->useTenant($alpha['tenant']);
        $form = Livewire::test(FileIncident::class, ['bookingId' => $fleet['booking']->id]);
        $this->assertOptionOrder($form->html(), [
            $fleet['assigned']->id,
            $fleet['able']->id,
            $fleet['baker']->id,
        ]);
        $form
            ->set('assetId', $fleet['shop']->id)
            ->set('severity', 'minor')
            ->set('notes', 'A scrape on a kayak that is in the shop.')
            ->call('file')
            ->assertHasErrors(['assetId'])
            ->set('assetId', $foreign->id)
            ->call('file')
            ->assertHasErrors(['assetId']);
        $this->assertSame(0, Incident::query()->count());

        Livewire::test(FileIncident::class, ['bookingId' => $fleet['booking']->id])
            ->set('assetId', $fleet['able']->id)
            ->set('severity', 'minor')
            ->set('notes', 'Scratch along the hull of the single kayak.')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.');

        $incident = Incident::query()->sole();
        $this->assertSame($fleet['able']->id, $incident->asset_id);
        $this->assertSame($fleet['booking']->id, $incident->booking_id);
        $this->assertSame(0, RentalCheckout::query()->count());
    }

    public function test_a_pool_allocation_can_be_reported_after_checkout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $fleet = $this->poolFleet($alpha['tenant'], $north, $south, assigned: false);
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        $checkedOut = app(Allocator::class)->checkout($fleet['booking']->fresh());
        $checkout = $checkedOut->checkouts->first();
        $this->assertInstanceOf(RentalCheckout::class, $checkout);
        $this->assertNull($checkout->asset_id);
        $this->assertSame($fleet['pool']->id, $checkout->pool_id);

        $form = Livewire::test(FileIncident::class, ['bookingId' => $fleet['booking']->id]);
        $this->assertOptionOrder($form->html(), [$fleet['able']->id, $fleet['baker']->id]);
        $form->assertDontSee('No boat is assigned to this booking yet.')
            ->assertDontSee('Shop kayak')
            ->assertDontSee('Zebra skiff')
            ->set('assetId', $fleet['baker']->id)
            ->set('severity', 'moderate')
            ->set('notes', 'The checked-out kayak has a cracked hatch.')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.');
        $this->assertSame($fleet['baker']->id, Incident::query()->sole()->asset_id);

        $checkout->asset_id = $fleet['shop']->id;
        $checkout->save();

        $checked = Livewire::test(FileIncident::class, ['bookingId' => $fleet['booking']->id]);
        $this->assertOptionOrder($checked->html(), [
            $fleet['shop']->id,
            $fleet['able']->id,
            $fleet['baker']->id,
        ]);
        $checked->assertDontSee('value="'.$fleet['assigned']->id.'"', false);
    }

    public function test_a_count_only_pool_is_reported_as_its_gear_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $harbor = $this->harborSingleKayak($alpha['tenant'], $north, 'Harbor Guest');
        $this->price($alpha['tenant'], $harbor['booking']);
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        Livewire::test(FileIncident::class, ['bookingId' => $harbor['booking']->id])
            ->assertSee('This report covers')
            ->assertSee('Single kayak')
            ->assertDontSee('Sam (guide)')
            ->assertDontSee('wire:model.live="assetId"', false)
            ->set('severity', 'serious')
            ->assertDontSee('Take this boat out for maintenance')
            ->set('blockAsset', true)
            ->set('notes', 'The single kayak hull is scraped.')
            ->set('damageAmount', '12.50')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.')
            ->assertSee('$12.50 was added to the customer total.');

        $incident = Incident::query()->sole();
        $this->assertNull($incident->asset_id);
        $this->assertSame($harbor['pool']->id, $incident->pool_id);
        $this->assertSame(1250, $incident->damage_cents);
        $this->assertSame(12_350, $this->total($harbor['booking']));
        $this->assertSame(0, MaintenanceWindow::query()->count());
        $this->assertSame(0, RentalCheckout::query()->count());

        app(TenantManager::class)->forget();
        $this->get($this->tenantUrl('alpha.test', '/staff/incidents'))
            ->assertOk()
            ->assertSee('Single kayak')
            ->assertDontSee('>Boat<', false);
    }

    public function test_a_pool_with_no_active_boats_is_reported_as_its_gear_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $this->useTenant($alpha['tenant']);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Single kayaks',
            'slug' => 'single-kayaks',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $north->id,
            'resource_category_id' => $category->id,
            'name' => 'Single kayak',
            'quantity' => 10,
            'status' => ResourceStatus::Active,
        ]);
        $this->kayak($alpha['tenant'], $north, $category, 'Shop kayak', ResourceStatus::Maintenance);
        $this->kayak($alpha['tenant'], $north, $category, 'Blocked kayak', ResourceStatus::Blocked);
        $booking = $this->poolBooking($alpha['tenant'], $north, $pool, 'Inactive Guest', null);
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        Livewire::test(FileIncident::class, ['bookingId' => $booking->id])
            ->assertSee('This report covers')
            ->assertSee('data-gear-type="'.$pool->id.'"', false)
            ->assertDontSee('Shop kayak')
            ->assertDontSee('Blocked kayak')
            ->set('severity', 'serious')
            ->assertDontSee('Take this boat out for maintenance')
            ->set('blockAsset', true)
            ->set('notes', 'Every single kayak is already out of service.')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.');

        $incident = Incident::query()->sole();
        $this->assertNull($incident->asset_id);
        $this->assertSame($pool->id, $incident->pool_id);
        $this->assertNull($incident->maintenance_window_id);
        $this->assertSame(0, MaintenanceWindow::query()->count());
    }

    public function test_boats_are_listed_from_the_pool_location(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $this->useTenant($alpha['tenant']);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Single kayaks',
            'slug' => 'single-kayaks',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'resource_category_id' => $category->id,
            'name' => 'Single kayak',
            'quantity' => 4,
            'status' => ResourceStatus::Active,
        ]);
        $southBoat = $this->kayak($alpha['tenant'], $south, $category, 'South Able', ResourceStatus::Active);
        $northBoat = $this->kayak($alpha['tenant'], $north, $category, 'North stray', ResourceStatus::Active);
        $booking = $this->poolBooking($alpha['tenant'], $north, $pool, 'Moved Guest', null);
        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);

        $form = Livewire::test(FileIncident::class, ['bookingId' => $booking->id]);
        $this->assertOptionOrder($form->html(), [$southBoat->id]);
        $form->assertDontSee('North stray')
            ->assertDontSee('This report covers')
            ->set('severity', 'serious')
            ->assertDontSee('Take this boat out for maintenance')
            ->set('assetId', $southBoat->id)
            ->assertSee('Take this boat out for maintenance')
            ->set('severity', 'minor')
            ->set('notes', 'The south kayak has a chipped bow.')
            ->call('file')
            ->assertHasNoErrors()
            ->assertSee('Report filed.');

        $incident = Incident::query()->sole();
        $this->assertSame($southBoat->id, $incident->asset_id);
        $this->assertNull($incident->pool_id);

        Livewire::test(FileIncident::class, ['bookingId' => $booking->id])
            ->set('assetId', $northBoat->id)
            ->set('severity', 'minor')
            ->set('notes', 'This boat is not in the pool.')
            ->call('file')
            ->assertHasErrors(['assetId']);
        $this->assertSame(1, Incident::query()->count());
    }

    private function location(Tenant $tenant, string $name): Location
    {
        $this->useTenant($tenant);

        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }

    /**
     * @return array{booking: Booking, checkout: RentalCheckout, asset: Asset}
     */
    private function openCheckout(
        Tenant $tenant,
        Location $location,
        string $customerName,
        string $assetName,
        string $checkedOutAt,
        string $dueAt,
    ): array {
        $this->useTenant($tenant);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $assetName,
        ]);
        $booked = $this->booked($tenant, $location, $asset, $customerName, $checkedOutAt, $dueAt);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booked['booking']->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);

        return [
            'booking' => $booked['booking'],
            'checkout' => $checkout,
            'asset' => $asset,
        ];
    }

    /**
     * @return array{booking: Booking}
     */
    private function booked(
        Tenant $tenant,
        Location $location,
        Asset $asset,
        string $customerName,
        string $startsAt,
        string $endsAt,
    ): array {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Day rental '.$customerName,
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$asset->id.$startsAt),
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        return ['booking' => $booking];
    }

    private function price(Tenant $tenant, Booking $booking, bool $withLateFee = false): void
    {
        $this->useTenant($tenant);
        $terms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($tenant, FeePayer::CustomerPaid);
        $this->assertNotNull($terms);
        $total = $withLateFee ? 12_100 : 11_100;
        $payload = [
            'services_cents' => 10_000,
            'basis_cents' => 10_000,
            'tax_cents' => 800,
            'fee_cents' => 300,
            'customer_fee_line_cents' => 300,
            'customer_total_cents' => $total,
            'fee_payer' => 'customer_paid',
        ];

        if ($withLateFee) {
            $payload['late_fees_cents'] = 1_000;
            $payload['late_fee_lines'] = [[
                'checkout_id' => 'late-fee',
                'minutes_late' => 20,
                'amount_cents' => 1_000,
            ]];
        }

        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'disclosure_version_id' => $terms->id,
            'payload' => $payload,
        ]);
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 11_100,
            'fee_cents' => 300,
        ]);
    }

    /**
     * @param  array{booking: Booking, asset: Asset}  $open
     */
    private function storedIncident(
        array $open,
        string $severity,
        string $notes,
        ?int $damageCents = null,
        IncidentStatus $status = IncidentStatus::Open,
    ): Incident {
        return Incident::query()->create([
            'tenant_id' => $open['booking']->tenant_id,
            'booking_id' => $open['booking']->id,
            'asset_id' => $open['asset']->id,
            'severity' => $severity,
            'summary' => $notes,
            'notes' => $notes,
            'damage_cents' => $damageCents,
            'status' => $status,
            'occurred_at' => now(),
        ]);
    }

    private function total(Booking $booking): int
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $total = $snapshot->payload['customer_total_cents'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return array{user: User, membership: Membership}
     */
    private function member(Tenant $tenant, string $role): array
    {
        $user = User::factory()->create();
        $membership = Membership::factory()->role($role)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        return compact('user', 'membership');
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();

        foreach ($locationIds as $locationId) {
            MembershipLocation::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'location_id' => $locationId,
            ]);
        }

        app(StaffLocationAccess::class)->forget();
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    /**
     * @param  list<string>  $ids
     */
    private function assertOptionOrder(string $html, array $ids): void
    {
        $cursor = -1;

        foreach ($ids as $id) {
            $at = strpos($html, 'value="'.$id.'"');
            $this->assertNotFalse($at);
            $this->assertGreaterThan($cursor, $at);
            $cursor = $at;
        }
    }

    /**
     * @return array{booking: Booking, pool: Pool, able: Asset, baker: Asset, shop: Asset, assigned: Asset}
     */
    private function poolFleet(Tenant $tenant, Location $location, Location $otherLocation, bool $assigned): array
    {
        $this->useTenant($tenant);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Single kayaks',
            'slug' => 'single-kayaks',
        ]);
        $tandems = ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Tandems',
            'slug' => 'tandems',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Single kayak',
            'quantity' => 10,
            'status' => ResourceStatus::Active,
        ]);
        $able = $this->kayak($tenant, $location, $category, 'Able kayak', ResourceStatus::Active);
        $baker = $this->kayak($tenant, $location, $category, 'Baker kayak', ResourceStatus::Active);
        $shop = $this->kayak($tenant, $location, $category, 'Shop kayak', ResourceStatus::Maintenance);
        $this->kayak($tenant, $location, $tandems, 'Tandem one', ResourceStatus::Active);
        $this->kayak($tenant, $otherLocation, $category, 'South kayak', ResourceStatus::Active);
        $zebra = $this->kayak($tenant, $location, $tandems, 'Zebra skiff', ResourceStatus::Active);
        $booking = $this->poolBooking($tenant, $location, $pool, 'Pool Guest', $assigned ? $zebra : null);

        return [
            'booking' => $booking,
            'pool' => $pool,
            'able' => $able,
            'baker' => $baker,
            'shop' => $shop,
            'assigned' => $zebra,
        ];
    }

    private function kayak(Tenant $tenant, Location $location, ResourceCategory $category, string $name, ResourceStatus $status): Asset
    {
        $this->useTenant($tenant);

        return Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name,
            'status' => $status,
        ]);
    }

    private function poolBooking(Tenant $tenant, Location $location, Pool $pool, string $customerName, ?Asset $assigned): Booking
    {
        $this->useTenant($tenant);
        $booking = $this->bareBooking($tenant, $location, $customerName);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);

        if ($assigned instanceof Asset) {
            Allocation::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'asset_id' => $assigned->id,
                'quantity' => 1,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
            ]);
        }

        return $booking;
    }

    /**
     * Harbor Demo's Single kayak rental: a count-only pool with no category
     * and no kayak rows. The guide asset is at the same dock and is not on
     * this allocation. The booking is pool-allocated and not checked out.
     *
     * @return array{booking: Booking, pool: Pool}
     */
    private function harborSingleKayak(Tenant $tenant, Location $location, string $customerName): array
    {
        $this->useTenant($tenant);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => null,
            'name' => 'Single kayak',
            'quantity' => 10,
            'status' => ResourceStatus::Active,
            'turnaround_minutes' => 15,
        ]);
        Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => null,
            'name' => 'Sam (guide)',
            'status' => ResourceStatus::Active,
            'capacity' => 1,
            'is_guide' => true,
        ]);

        return [
            'booking' => $this->poolBooking($tenant, $location, $pool, $customerName, null),
            'pool' => $pool,
        ];
    }

    private function bareBooking(Tenant $tenant, Location $location, string $customerName): Booking
    {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Day rental '.$customerName,
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 16:00:00',
            'ends_at' => '2026-06-16 18:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$location->id),
        ]);
    }
}
