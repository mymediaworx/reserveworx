<?php

namespace Tests\Feature\Web;

use App\Enums\ImportStatus;
use App\Enums\MembershipStatus;
use App\Enums\ResourceStatus;
use App\Jobs\ProcessCsvImport;
use App\Livewire\Staff\Import;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\CsvImport;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffImportTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function csv(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function workOnce(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 3,
            '--max-time' => 20,
            '--memory' => 1024,
        ])->assertSuccessful();
    }

    private function workAll(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 3,
            '--max-time' => 20,
            '--memory' => 1024,
        ])->assertSuccessful();
    }

    public function test_an_owner_previews_twenty_rows_and_imports_the_rest_in_chunks(): void
    {
        Storage::fake('local');
        config(['imports.chunk' => 2]);
        $workspace = $this->signedIn();
        $lines = ['Person,Contact,Tel'];

        for ($i = 1; $i <= 19; $i++) {
            $lines[] = "Person {$i},person{$i}@example.com,312-555-0100";
        }

        $lines[] = 'Bad Row,not-an-email,312-555-0100';
        $lines[] = 'Last Person,last-row@example.com,312-555-0199';

        Livewire::test(Import::class)
            ->set('type', 'customers')
            ->set('file', $this->csv('people.csv', implode("\n", $lines)."\n"))
            ->call('stageFile')
            ->assertHasNoErrors()
            ->set('mapping.name', '0')
            ->set('mapping.email', '1')
            ->set('mapping.phone', '2')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Email is not a valid address.')
            ->assertSee('person1@example.com')
            ->assertDontSee('last-row@example.com')
            ->call('runImport')
            ->assertHasNoErrors();

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->workOnce();
        app(TenantManager::class)->set($workspace['tenant']);
        $import = CsvImport::query()->firstOrFail();
        $this->assertSame(ImportStatus::Processing, $import->status);
        $this->assertSame(2, $import->processed_rows);
        $this->assertSame(2, $import->created_count);
        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->workAll();
        app(TenantManager::class)->set($workspace['tenant']);
        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(20, $import->created_count);
        $this->assertSame(0, $import->updated_count);
        $this->assertSame(1, $import->skipped_count);
        $this->assertSame(21, $import->processed_rows);
        $this->assertSame(20, Customer::query()->count());
        $this->assertNotNull(Customer::query()->where('email', 'last-row@example.com')->first());
        $this->assertNull(Customer::query()->where('email', 'not-an-email')->first());
        Storage::disk('local')->assertMissing($import->path);

        $completed = AuditLog::query()->where('action', 'import.completed')->first();
        $this->assertNotNull($completed);
        $this->assertSame($workspace['user']->id, $completed->actor_user_id);
        $this->assertSame('customers', $completed->properties['type'] ?? null);
        $this->assertSame(20, $completed->properties['created'] ?? null);
        $this->assertSame(0, $completed->properties['updated'] ?? null);
        $this->assertSame(1, $completed->properties['skipped'] ?? null);
        $this->assertSame(21, $completed->properties['rows'] ?? null);
        $this->assertSame(1, AuditLog::query()->where('action', 'import.queued')->count());

        Livewire::test(Import::class)
            ->assertSee('Import finished')
            ->assertSee('Row 21: Email is not a valid address.')
            ->assertSee('data-import-created="20"', false)
            ->assertSee('data-import-skipped="1"', false);
    }

    public function test_duplicate_customers_are_updated_and_other_workspaces_stay_separate(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn();
        $beta = $this->workspace('beta.test');
        Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Old Name',
            'email' => 'Ada@Example.com',
            'phone' => '312-555-0000',
        ]);
        Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Gone Guest',
            'email' => 'gone@example.com',
            'anonymized_at' => now(),
        ]);
        app(TenantManager::class)->set($beta['tenant']);
        Customer::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta Ada',
            'email' => 'ada@example.com',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        $csv = implode("\n", [
            'Full name;E-mail;Mobile',
            'Ada Lovelace;ada@example.com;312-555-0101',
            'Ada Updated;ADA@example.com;312-555-0199',
            'Ghost;gone@example.com;312-555-0102',
            'New Person;new@example.com;312-555-0103',
        ])."\n";

        Livewire::test(Import::class)
            ->set('file', $this->csv('customers.csv', $csv))
            ->call('stageFile')
            ->assertSet('mapping.name', '0')
            ->assertSet('mapping.email', '1')
            ->assertSet('mapping.phone', '2')
            ->call('preview')
            ->assertSee('This email already belongs to a customer')
            ->assertSee('That customer was anonymized and was left unchanged.')
            ->call('runImport');

        $this->assertSame(2, Customer::query()->count());
        $this->workAll();
        app(TenantManager::class)->set($workspace['tenant']);

        $this->assertSame(3, Customer::query()->count());
        $ada = Customer::query()->whereRaw('LOWER(email) = ?', ['ada@example.com'])->firstOrFail();
        $this->assertSame('Ada Updated', $ada->name);
        $this->assertSame('ada@example.com', $ada->email);
        $this->assertSame('312-555-0199', $ada->phone);
        $gone = Customer::query()->where('email', 'gone@example.com')->firstOrFail();
        $this->assertSame('Gone Guest', $gone->name);
        $this->assertNotNull($gone->anonymized_at);
        $this->assertNotNull(Customer::query()->where('email', 'new@example.com')->first());

        $import = CsvImport::query()->firstOrFail();
        $this->assertSame(1, $import->created_count);
        $this->assertSame(2, $import->updated_count);
        $this->assertSame(1, $import->skipped_count);

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame('Beta Ada', Customer::query()->where('email', 'ada@example.com')->firstOrFail()->name);
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_inventory_rows_are_created_updated_and_skipped(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        app(TenantManager::class)->set($workspace['tenant']);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Kayaks',
        ]);
        Asset::factory()->create([
            'location_id' => $shop['location']->id,
            'name' => 'Old pontoon',
            'status' => ResourceStatus::Retired,
        ]);
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $betaLocation = Location::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'North dock',
        ]);
        $betaPool = Pool::factory()->create([
            'location_id' => $betaLocation->id,
            'name' => 'Single kayak',
            'quantity' => 7,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        $csv = implode("\n", [
            'kind,name,location,category,quantity,capacity,status,is_guide,prep_buffer_minutes,return_buffer_minutes,turnaround_minutes',
            'pool,Single kayak,North dock,Kayaks,20,1,active,no,5,5,10',
            'pool,Life jackets,North dock,Kayaks,12,,,,,,',
            'asset,Pontoon 1,North dock,Kayaks,,8,active,yes,10,10,20',
            'pool,Mystery,Missing dock,Kayaks,4,,,,,,',
            'asset,Old pontoon,North dock,,,1,active,no,,,',
            'pool,Spare paddles,North dock,,3,,,,,,',
            'asset,Pontoon 1,north dock,Kayaks,,10,blocked,no,10,10,20',
        ])."\n";

        Livewire::test(Import::class)
            ->set('type', 'inventory')
            ->set('file', $this->csv('gear.csv', $csv))
            ->call('stageFile')
            ->assertSet('mapping.kind', '0')
            ->assertSet('mapping.name', '1')
            ->call('preview')
            ->assertSee('That location is not in this workspace.')
            ->assertSee('That item is retired and was left in place.')
            ->assertSee('An item with this name is already at this location.')
            ->call('runImport');

        $this->workAll();
        app(TenantManager::class)->set($workspace['tenant']);

        $import = CsvImport::query()->firstOrFail();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(3, $import->created_count);
        $this->assertSame(2, $import->updated_count);
        $this->assertSame(2, $import->skipped_count);

        $kayaks = Pool::query()->where('name', 'Single kayak')->firstOrFail();
        $this->assertSame(20, $kayaks->quantity);
        $this->assertSame(5, $kayaks->prep_buffer_minutes);
        $this->assertSame($category->id, $kayaks->resource_category_id);
        $this->assertSame($shop['location']->id, $kayaks->location_id);

        $jackets = Pool::query()->where('name', 'Life jackets')->firstOrFail();
        $this->assertSame(12, $jackets->quantity);
        $this->assertSame($category->id, $jackets->resource_category_id);

        $paddles = Pool::query()->where('name', 'Spare paddles')->firstOrFail();
        $this->assertSame(3, $paddles->quantity);
        $this->assertNull($paddles->resource_category_id);

        $pontoon = Asset::query()->where('name', 'Pontoon 1')->get();
        $this->assertCount(1, $pontoon);
        $this->assertSame(10, $pontoon->first()?->capacity);
        $this->assertFalse($pontoon->first()?->is_guide);
        $this->assertSame(ResourceStatus::Blocked, $pontoon->first()?->status);
        $this->assertSame(20, $pontoon->first()?->turnaround_minutes);
        $this->assertSame(ResourceStatus::Retired, Asset::query()->where('name', 'Old pontoon')->firstOrFail()->status);
        $this->assertNull(Pool::query()->where('name', 'Mystery')->first());

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(7, $betaPool->fresh()?->quantity);
    }

    public function test_a_manager_can_import_and_staff_cannot(): void
    {
        Storage::fake('local');
        $manager = $this->signedIn('manager');

        $this->get($this->tenantUrl('alpha.test', '/staff/import'))
            ->assertOk()
            ->assertSee('Upload a CSV')
            ->assertSee('Customer template');
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('/staff/import', false);

        app(TenantManager::class)->set($manager['tenant']);
        Livewire::test(Import::class)
            ->set('file', $this->csv('one.csv', "name,email,phone\nAda Lovelace,ada@example.com,312-555-0100\n"))
            ->call('stageFile')
            ->call('preview')
            ->call('runImport');
        $this->workAll();
        app(TenantManager::class)->set($manager['tenant']);
        $this->assertSame('Ada Lovelace', Customer::query()->where('email', 'ada@example.com')->firstOrFail()->name);

        $staff = $this->workspace('staff.test', 'staff');
        $this->actingAs($staff['user']);
        $this->get($this->tenantUrl('staff.test', '/staff/import'))->assertForbidden();
        $this->get($this->tenantUrl('staff.test', '/staff/import/templates/customers'))->assertForbidden();
        $this->get($this->tenantUrl('staff.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('/staff/import', false);

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Import::class)->assertForbidden();
    }

    public function test_templates_download_and_bad_files_are_refused(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn();

        $customers = $this->get($this->tenantUrl('alpha.test', '/staff/import/templates/customers'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $customerCsv = $customers->streamedContent();
        $this->assertStringContainsString('name,email,phone', $customerCsv);
        $this->assertStringContainsString('ada@example.com', $customerCsv);

        $inventory = $this->get($this->tenantUrl('alpha.test', '/staff/import/templates/inventory'))->assertOk();
        $this->assertStringContainsString('kind,name,location,category,quantity,capacity,status,is_guide,prep_buffer_minutes,return_buffer_minutes,turnaround_minutes', $inventory->streamedContent());
        $this->assertStringContainsString('Single kayaks', $inventory->streamedContent());

        $this->get($this->tenantUrl('alpha.test', '/staff/import/templates/bookings'))->assertNotFound();

        app(TenantManager::class)->forget();
        $this->app['auth']->forgetGuards();
        $this->get($this->tenantUrl('alpha.test', '/staff/import'))->assertRedirect('/staff/login');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        config(['imports.max_rows' => 2]);

        Livewire::test(Import::class)
            ->set('file', UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))
            ->call('stageFile')
            ->assertHasErrors('file');

        Livewire::test(Import::class)
            ->set('file', $this->csv('empty.csv', ''))
            ->call('stageFile')
            ->assertHasErrors('file')
            ->assertSee('That file has no header row.');

        Livewire::test(Import::class)
            ->set('file', $this->csv('wide.csv', "name,email,phone\nA,a@example.com,\nB,b@example.com,\nC,c@example.com,\n"))
            ->call('stageFile')
            ->assertHasErrors('file')
            ->assertSee('more than 2 rows');

        Livewire::test(Import::class)
            ->set('file', $this->csv('latin.csv', "name,email,phone\nAda,ada@example.com,\xFF\n"))
            ->call('stageFile')
            ->assertHasErrors('file')
            ->assertSee('Save the file as UTF-8');

        config(['imports.max_rows' => 100000]);
        Livewire::test(Import::class)
            ->set('file', $this->csv('notes.csv', "Notes\nHello\n"))
            ->call('stageFile')
            ->call('preview')
            ->assertHasErrors(['mapping.name', 'mapping.email']);

        $this->assertSame(1, CsvImport::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_revoked_owner_does_not_import_and_another_workspace_cannot_read_the_file(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn();
        $beta = $this->workspace('beta.test');

        Livewire::test(Import::class)
            ->set('file', $this->csv('one.csv', "name,email,phone\nAda Lovelace,ada@example.com,\n"))
            ->call('stageFile')
            ->call('preview')
            ->call('runImport');

        $import = CsvImport::query()->firstOrFail();
        $workspace['membership']->status = MembershipStatus::Revoked;
        $workspace['membership']->save();
        $this->workAll();
        app(TenantManager::class)->set($workspace['tenant']);
        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertSame('The person who started this import is no longer an owner or manager.', $import->failure);
        $this->assertSame(0, Customer::query()->count());
        Storage::disk('local')->assertMissing($import->path);

        app(TenantManager::class)->set($workspace['tenant']);
        $workspace['membership']->status = MembershipStatus::Active;
        $workspace['membership']->save();
        Livewire::test(Import::class)
            ->set('file', $this->csv('two.csv', "name,email,phone\nBea Knits,bea@example.com,\n"))
            ->call('stageFile');
        $second = CsvImport::query()->where('status', ImportStatus::Uploaded)->firstOrFail();

        try {
            (new ProcessCsvImport($beta['tenant']->id, $second->id))->handle(app(TenantManager::class));
            $this->fail('A different workspace was imported.');
        } catch (TenantContextException $exception) {
            $this->assertSame('Refusing to import a file for a different workspace.', $exception->getMessage());
        }

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ImportStatus::Uploaded, $second->fresh()?->status);
        $this->assertSame(0, Customer::query()->count());
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_location_limit_blocks_customer_import_and_hides_other_locations(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn('manager');
        $home = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Home dock',
        ]);
        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Other dock',
        ]);
        $workspace['membership']->locations_restricted = true;
        $workspace['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'membership_id' => $workspace['membership']->id,
            'location_id' => $home->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        Livewire::test(Import::class)
            ->set('file', $this->csv('people.csv', "name,email,phone\nAda Lovelace,ada@example.com,\n"))
            ->call('stageFile')
            ->call('preview')
            ->assertHasErrors('file')
            ->assertSee('Customer import needs access to every location.');

        $this->assertSame(0, Customer::query()->count());

        Livewire::test(Import::class)
            ->set('type', 'inventory')
            ->set('file', $this->csv('gear.csv', "kind,name,location,quantity\npool,Life jackets,Home dock,4\npool,Mystery,Other dock,4\n"))
            ->call('stageFile')
            ->call('preview')
            ->assertSee('That location is not in this workspace.')
            ->call('runImport');

        $this->workAll();
        app(TenantManager::class)->set($workspace['tenant']);
        $created = Pool::query()->where('name', 'Life jackets')->first();
        $this->assertNotNull($created);
        $this->assertSame($home->id, $created->location_id);
        $this->assertSame(4, $created->quantity);
        $this->assertNull(Pool::query()->where('name', 'Mystery')->first());
    }
}
