<?php

namespace Tests\Feature\Allocation;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Services\Allocation\BucketPlanner;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use CreatesWorkspace, DatabaseMigrations;

    public function test_two_requests_for_the_last_asset(): void
    {
        [$service] = $this->serviceOnAsset();
        $this->contend($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', asset: true);
    }

    public function test_two_requests_for_the_last_units_of_a_pool(): void
    {
        [$service] = $this->serviceOnPool(quantity: 2, units: 2);
        $this->contend($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', asset: false);
    }

    public function test_a_tour_and_a_rental_compete_for_the_same_kayaks(): void
    {
        [$rental, $tour] = $this->sharedKayaks();
        $this->contend($rental, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', asset: false, winnerService: $tour);
    }

    public function test_an_extension_interval_competes_with_a_new_booking(): void
    {
        [$service, $asset] = $this->serviceOnAsset();
        $this->seedConfirmed($service, $asset->id, '2026-06-15 14:00:00', '2026-06-15 15:00:00');
        $this->contend($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', asset: true);
    }

    public function test_a_multi_day_new_york_transition_still_allows_only_one_winner(): void
    {
        [$service] = $this->serviceOnAsset(timezone: 'America/New_York');
        $start = CarbonImmutable::parse('2026-03-07 10:00:00', 'America/New_York')->utc();
        $end = CarbonImmutable::parse('2026-03-09 10:00:00', 'America/New_York')->utc();
        $this->assertSame('2026-03-07T15:00:00Z', $start->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-03-09T14:00:00Z', $end->format('Y-m-d\TH:i:s\Z'));

        $fallStart = CarbonImmutable::parse('2026-10-31 10:00:00', 'America/New_York')->utc();
        $fallEnd = CarbonImmutable::parse('2026-11-02 10:00:00', 'America/New_York')->utc();
        $this->assertSame('2026-10-31T14:00:00Z', $fallStart->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-11-02T15:00:00Z', $fallEnd->format('Y-m-d\TH:i:s\Z'));

        $this->contend($service, $start->toIso8601String(), $end->toIso8601String(), asset: true);
    }

    private function contend(Service $service, string $startsAt, string $endsAt, bool $asset, ?Service $winnerService = null): void
    {
        $winnerService ??= $service;
        $requirement = ResourceRequirement::withoutGlobalScope(TenantScope::class)
            ->where('service_id', $service->id)
            ->firstOrFail();
        $resourceId = $requirement->resource_id;
        $quantity = $requirement->quantity;

        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        DB::beginTransaction();
        DB::statement('set innodb_lock_wait_timeout = 15');

        if ($asset) {
            DB::select('select id from assets where id = ? for update', [$resourceId]);
        } else {
            DB::select('select id from pools where id = ? for update', [$resourceId]);
            foreach ($this->buckets($startsAt, $endsAt) as $bucket) {
                DB::table('capacity_ledger')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $service->tenant_id,
                    'pool_id' => $resourceId,
                    'bucket_start_utc' => $bucket,
                    'allocated_units' => 0,
                    'held_units' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::select(
                    'select id from capacity_ledger where pool_id = ? and bucket_start_utc = ? for update',
                    [$resourceId, $bucket],
                );
            }
        }

        $payload = tempnam(sys_get_temp_dir(), 'hold-');
        $this->assertNotFalse($payload);
        file_put_contents($payload, json_encode([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 1,
            'idempotency_key' => 'child-'.Str::lower(Str::random(8)),
        ], JSON_THROW_ON_ERROR));

        $bookingsBefore = DB::table('bookings')->count();
        $allocationsBefore = DB::table('allocations')->count();
        $holdsBefore = DB::table('holds')->count();

        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/concurrent_hold.php'), $payload],
            base_path(),
            $this->childEnvironment(),
        );
        $process->setTimeout(30);
        $started = microtime(true);
        $process->start();

        $waited = $this->waitForLock($process, $started);
        $bookingId = (string) Str::ulid();
        DB::table('bookings')->insert([
            'id' => $bookingId,
            'tenant_id' => $winnerService->tenant_id,
            'location_id' => $winnerService->location_id,
            'service_id' => $winnerService->id,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => CarbonImmutable::parse($startsAt)->utc()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc()->format('Y-m-d H:i:s'),
            'participants' => 1,
            'idempotency_key' => 'winner-'.Str::lower(Str::random(8)),
            'payload_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('allocations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $winnerService->tenant_id,
            'booking_id' => $bookingId,
            'asset_id' => $asset ? $resourceId : null,
            'pool_id' => $asset ? null : $resourceId,
            'quantity' => $quantity,
            'starts_at' => CarbonImmutable::parse($startsAt)->utc()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::commit();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        $process->wait();
        @unlink($payload);

        $this->assertTrue($waited, 'The second connection did not wait on the row lock. '.$process->getOutput().$process->getErrorOutput());
        $this->assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('Allocator.php:'.$this->lockWaitGiveUpLine(), $process->getErrorOutput());
        $this->assertSame($bookingsBefore + 1, DB::table('bookings')->count());
        $this->assertSame($allocationsBefore + 1, DB::table('allocations')->count());
        $this->assertSame($holdsBefore, DB::table('holds')->count());
        $this->assertSame(0, DB::table('capacity_ledger')->where('allocated_units', '<', 0)->orWhere('held_units', '<', 0)->count());
        $used = (int) DB::table('allocations')->where('pool_id', $resourceId)->sum('quantity');
        if (! $asset) {
            $pool = Pool::withoutGlobalScope(TenantScope::class)->findOrFail($resourceId);
            $this->assertLessThanOrEqual($pool->quantity, $used);
        }
    }

    private function lockWaitGiveUpLine(): int
    {
        $lines = file(app_path('Services/Allocation/Allocator.php'));

        if ($lines === false) {
            $this->fail('Allocator source could not be read.');
        }

        foreach ($lines as $number => $line) {
            if (str_contains($line, 'lock-wait-exhausted')) {
                return $number + 1;
            }
        }

        $this->fail('The lock-wait give-up marker is missing from Allocator.');
    }

    private function waitForLock(Process $process, float $started): bool
    {
        $deadline = $started + 4;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                return false;
            }

            $state = (int) (DB::select("select count(*) as n from information_schema.processlist where id <> connection_id() and (state like '%lock%' or state like '%wait%' or info like '%for update%')")[0]->n ?? 0);

            try {
                $schema = (int) (DB::select('select count(*) as n from performance_schema.data_lock_waits')[0]->n ?? 0);
            } catch (\Throwable) {
                $schema = 0;
            }

            if ($state > 0 || $schema > 0 || (microtime(true) - $started) > 1.2) {
                return true;
            }

            usleep(50000);
        }

        return $process->isRunning();
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $keys = ['APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER', 'PLATFORM_DOMAINS'];
        $env = [];

        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    private function buckets(string $startsAt, string $endsAt): array
    {
        return array_map(
            fn (int $unix): string => CarbonImmutable::parse('@'.$unix)->utc()->format('Y-m-d H:i:s'),
            app(BucketPlanner::class)->covering(CarbonImmutable::parse($startsAt), CarbonImmutable::parse($endsAt), 15),
        );
    }

    /**
     * @return array{0: Service, 1: Asset}
     */
    private function serviceOnAsset(string $timezone = 'UTC'): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create(['tenant_id' => $workspace['tenant']->id, 'timezone' => $timezone]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
        ]);
        $service = Service::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);

        return [$service, $asset];
    }

    /**
     * @return array{0: Service, 1: Pool}
     */
    private function serviceOnPool(int $quantity, int $units): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create(['tenant_id' => $workspace['tenant']->id, 'timezone' => 'UTC']);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
        ]);
        $service = Service::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Pool rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => $units,
        ]);

        return [$service, $pool];
    }

    /**
     * @return array{0: Service, 1: Service}
     */
    private function sharedKayaks(): array
    {
        [$rental, $pool] = $this->serviceOnPool(4, 4);
        $tour = Service::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $rental->tenant_id,
            'location_id' => $rental->location_id,
            'name' => 'Tour',
            'kind' => 'tour',
        ]);
        ResourceRequirement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $rental->tenant_id,
            'service_id' => $tour->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 4,
        ]);

        return [$rental, $tour];
    }

    private function seedConfirmed(Service $service, string $assetId, string $start, string $end): void
    {
        $bookingId = (string) Str::ulid();
        DB::table('bookings')->insert([
            'id' => $bookingId,
            'tenant_id' => $service->tenant_id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'payload_hash' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('allocations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $service->tenant_id,
            'booking_id' => $bookingId,
            'asset_id' => $assetId,
            'quantity' => 1,
            'starts_at' => $start,
            'ends_at' => $end,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
